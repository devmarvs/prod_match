<?php
declare(strict_types=1);

namespace LengowExample;

/** An intentionally conservative, explainable heuristic. No trained/calibrated confidence scores. */
final class Matcher
{
    /** JSON encoding avoids the ambiguity of joining composite-key values with a pipe. */
    public static function identity(array $product): string
    {
        return json_encode([(string)($product['StoreID'] ?? ''), (string)($product['Code'] ?? ''), (string)($product['SubCode'] ?? '')], JSON_THROW_ON_ERROR);
    }

    public static function attributes(string $raw): array
    {
        $out = [];
        foreach (explode('[|]', $raw) as $part) {
            $split = explode('=>', $part, 2);
            if (count($split) !== 2 || trim($split[0]) === '') continue;
            $out[trim($split[0])][] = trim($split[1]);
        }
        return $out;
    }

    /** Canonical GTIN-14, retaining any original leading zeroes in the numeric representation. */
    public static function gtin(string $raw): ?string
    {
        $digits = preg_replace('/[\s-]+/u', '', trim($raw));
        if ($digits === null || !preg_match('/^(?:\d{8}|\d{12,14})$/D', $digits)) return null;
        $sum = 0;
        $reverse = strrev(substr($digits, 0, -1));
        for ($i = 0, $n = strlen($reverse); $i < $n; $i++) {
            $sum += (int)$reverse[$i] * ($i % 2 === 0 ? 3 : 1);
        }
        if ((10 - ($sum % 10)) % 10 !== (int)substr($digits, -1)) return null;
        return str_pad($digits, 14, '0', STR_PAD_LEFT);
    }

    /** Preserve provenance. A promoted EAN is NOT independent corroboration of its original source. */
    public static function eanEvidence(array $attrs): array
    {
        $all = [];
        foreach (['EAN', 'EAN_', 'GOOGLE_EAN', 'SEARCH_BOX_EAN', 'EAN_DEDUCTED'] as $key) {
            foreach ($attrs[$key] ?? [] as $value) {
                if (($gtin = self::gtin($value)) !== null) $all[] = ['value' => $gtin, 'source' => $key];
            }
        }
        if ($all === []) return ['value' => null, 'sources' => [], 'reliable' => false, 'conflicting' => false];
        $values = array_values(array_unique(array_column($all, 'value')));
        $sources = array_values(array_unique(array_column($all, 'source')));
        if (count($values) > 1) return ['value' => null, 'sources' => $sources, 'reliable' => false, 'conflicting' => true];
        $derivedKeys = ['EAN_DEDUCTED', 'GOOGLE_EAN', 'SEARCH_BOX_EAN', 'EAN_'];
        $derived = count(array_intersect($derivedKeys, $sources)) > 0
            || isset($attrs['EAN_FROM_REF']) || isset($attrs['EAN-FROM-HTML']);
        return ['value' => $values[0], 'sources' => $sources,
            'reliable' => in_array('EAN', $sources, true) && !$derived, 'conflicting' => false];
    }

    public static function normalize(string $text): string
    {
        $text = trim($text);
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($ascii !== false) $text = $ascii;
        }
        return preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '';
    }

    public static function brandEvidence(array $attrs): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn(string $v): string => self::normalize($v), $attrs['#brand#'] ?? []
        ))));
        return ['value' => count($normalized) === 1 ? $normalized[0] : null,
            'conflicting' => count($normalized) > 1,
            'missing' => count($normalized) === 0];
    }

    public static function tokens(string $text): array
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($text));
        if ($ascii !== false) $text = $ascii;
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($tokens, fn(string $v): bool => strlen($v) >= 2)));
    }

    /** Non-generic title evidence used as an extra safety gate for auto-connections. */
    public static function distinctiveTitleTokens(string $title, string $brand): array
    {
        $generic = array_fill_keys(explode(' ',
            'the for and with from les des dans pour avec homme femme men women womens mens '
            . 'chaussure chaussures shoe shoes sneaker sneakers boot boots trainer trainers footwear '
            . 'running trail de du la le et un une en size taille pointure eu uk us '
            . 'blue bleu bleue green vert verte red rouge black noir noire white blanc blanche pink rose '
            . 'grey gray gris yellow jaune brown marron orange purple violet pack lot of '
            . 'new original classic sports sport'), true);
        foreach (self::tokens($brand) as $token) $generic[$token] = true;
        return array_values(array_filter(self::tokens($title),
            fn(string $token): bool => !isset($generic[$token]) && !ctype_digit($token)));
    }

    public static function overlap(string $a, string $b): float
    {
        $aTokens = self::tokens($a); $bTokens = self::tokens($b);
        if (!$aTokens || !$bTokens) return 0.0;
        return count(array_intersect($aTokens, $bTokens)) / count(array_unique(array_merge($aTokens, $bTokens)));
    }

    private static function cleanSize(string $raw): ?string
    {
        $s = strtolower(trim(str_replace([',', '⅓', '⅔'], ['.', '.1/3', '.2/3'], $raw)));
        $s = preg_replace('/\s+/u', '', $s) ?? $s;
        // 431/3 -> 43.1/3, 43 1/3 -> 43.1/3, 43.1/3 unchanged.
        $s = preg_replace('/^(\d{2})([12]\/3)$/', '$1.$2', $s) ?? $s;
        if (preg_match('/^\d{1,2}(?:\.\d+|\.[12]\/3)?$/D', $s)) return $s;
        return null;
    }

    /** Extract only explicitly described dimensions; never interpret the model number 'Torrent 4' as shoe size. */
    public static function variants(array $product, array $attrs): array
    {
        $dimensions = ['size' => [], 'color' => [], 'capacity' => [], 'pack' => []];
        $add = static function (string $dim, ?string $value) use (&$dimensions): void {
            if ($value !== null && $value !== '') $dimensions[$dim][$value] = true;
        };
        foreach (['#size#', '#shoe_size#'] as $key) {
            foreach ($attrs[$key] ?? [] as $size) $add('size', self::cleanSize($size));
        }
        // In the supplied Intersport sample, SubTitle is frequently a numeric shoe size.
        $subtitle = (string)($product['SubTitle'] ?? '');
        $add('size', self::cleanSize($subtitle));
        $title = (string)($product['Title'] ?? '');
        if (preg_match_all('/\b(?:size|taille|pointure|talla|eu)\s*[:#-]?\s*(\d{1,2}(?:[.,]\d+|\s*[12]\/3)?)\b/iu', $title, $matches)) {
            foreach ($matches[1] as $size) $add('size', self::cleanSize($size));
        }
        // Do not compare colour spellings without an explicit small translation map.
        $colors = [
            'blue'=>'blue','bleu'=>'blue','bleue'=>'blue','green'=>'green','vert'=>'green','verte'=>'green',
            'black'=>'black','noir'=>'black','noire'=>'black','white'=>'white','blanc'=>'white','blanche'=>'white',
            'red'=>'red','rouge'=>'red','pink'=>'pink','rose'=>'pink','grey'=>'grey','gray'=>'grey','gris'=>'grey',
            'yellow'=>'yellow','jaune'=>'yellow','brown'=>'brown','marron'=>'brown','purple'=>'purple',
            'violet'=>'purple','orange'=>'orange',
        ];
        // Explicit colour attributes are treated as complete values, not inferred from any unrelated words.
        foreach ($attrs['#color#'] ?? [] as $color) {
            $parts = preg_split('/[^a-z]+/', strtolower((string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $color))) ?: [];
            foreach ($parts as $part) if (isset($colors[$part])) $add('color', $colors[$part]);
        }
        $t = strtolower((string)(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title));
        foreach (preg_split('/[^a-z]+/', $t) ?: [] as $word) {
            if (isset($colors[$word])) $add('color', $colors[$word]);
        }
        // Dimension-specific comparisons only when explicit units or pack markers are present.
        if (preg_match_all('/\b(\d+(?:[.,]\d+)?)\s*(gb|tb|ml|l)\b/i', $title, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $number = (float)str_replace(',', '.', $m[1]);
                $unit = strtolower($m[2]);
                $base = in_array($unit, ['tb', 'gb'], true) ? 'gb' : 'ml';
                $quantity = match ($unit) { 'tb' => $number * 1024, 'l' => $number * 1000, default => $number };
                $add('capacity', sprintf('%g%s', $quantity, $base));
            }
        }
        if (preg_match_all('/\b(?:pack\s*(?:of|de)?\s*|lot\s*de\s*)(\d{1,3})\b|\b(\d{1,3})\s*[- ]?pack\b/i', $title, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) $add('pack', (string)(int)($m[1] ?: $m[2]));
        }
        $output = [];
        foreach ($dimensions as $name => $values) $output[$name] = array_keys($values);
        return $output;
    }

    public static function active(array $product): bool
    {
        $status = trim((string)($product['Active'] ?? ''));
        return $status === '' || !is_numeric($status) || (float)$status > 0;
    }

    /** $context can contain ambiguity information learned from other candidates in the same store. */
    public static function evaluate(array $private, array $public, array $context = []): array
    {
        $pa = self::attributes((string)($private['Attributes'] ?? ''));
        $qa = self::attributes((string)($public['Attributes'] ?? ''));
        $pE = self::eanEvidence($pa); $qE = self::eanEvidence($qa);
        $pb = self::brandEvidence($pa); $qb = self::brandEvidence($qa);
        $pVariants = self::variants($private, $pa); $qVariants = self::variants($public, $qa);
        $reasons = []; $hardStop = false; $missingVariant = false; $matchedVariant = false;
        $sizeMatch = false; $partialColor = false;
        foreach ([[$private, $pa], [$public, $qa]] as [$record, $recordAttrs]) {
            $attributeOnlyColors = self::variants(['Title'=>'', 'SubTitle'=>''], $recordAttrs)['color'];
            $titleOnlyColors = self::variants(['Title'=>(string)($record['Title'] ?? ''), 'SubTitle'=>''], [])['color'];
            if ($attributeOnlyColors && $titleOnlyColors && !array_intersect($attributeOnlyColors, $titleOnlyColors)) {
                $reasons[] = 'color_attribute_title_conflict'; $hardStop = true;
            } elseif (count($attributeOnlyColors) > 1 && !$titleOnlyColors) {
                $partialColor = true;
            }
        }
        $categoryText = strtolower((string)($private['CategoryTags'] ?? '') . ' ' . (string)($public['CategoryTags'] ?? '') . ' ' . (string)($private['Title'] ?? '') . ' ' . (string)($public['Title'] ?? ''));
        $requiresSize = preg_match('/\b(?:shoes?|sneakers?|boots?|trainers?|chaussures?|footwear|pointure)\b/i', $categoryText) === 1;
        if (!self::active($private) || !self::active($public)) { $reasons[] = 'inactive_product'; $hardStop = true; }
        if ($pb['conflicting'] || $qb['conflicting']) { $reasons[] = 'conflicting_brand_values'; $hardStop = true; }
        $pModels = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $pa['#model#'] ?? []))));
        $qModels = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $qa['#model#'] ?? []))));
        if (count($pModels) > 1 || count($qModels) > 1
            || ($pModels && $qModels && $pModels[0] !== $qModels[0])) {
            $reasons[] = 'model_conflict'; $hardStop = true;
        }
        $brandConflict = $pb['value'] !== null && $qb['value'] !== null && $pb['value'] !== $qb['value'];
        $brandMatch = $pb['value'] !== null && $pb['value'] === $qb['value'];
        if ($brandConflict) { $reasons[] = 'brand_conflict'; $hardStop = true; }
        if ($pE['conflicting'] || $qE['conflicting']) { $reasons[] = 'conflicting_ean_values'; $hardStop = true; }
        $eanEqual = $pE['value'] !== null && $pE['value'] === $qE['value'];
        if ($pE['value'] !== null && $qE['value'] !== null && !$eanEqual) { $reasons[] = 'different_valid_gtin'; $hardStop = true; }
        foreach ($pVariants as $dim => $pValues) {
            $qValues = $qVariants[$dim];
            // Multiple sizes/capacities/pack counts within one record are internally ambiguous.
            if (($dim !== 'color') && (count($pValues) > 1 || count($qValues) > 1)) {
                $reasons[] = 'ambiguous_' . $dim; $hardStop = true; continue;
            }
            if ($pValues && $qValues) {
                if ($dim === 'color' ? !array_intersect($pValues, $qValues) : $pValues[0] !== $qValues[0]) {
                    $reasons[] = $dim . '_conflict'; $hardStop = true;
                } else {
                    if ($dim === 'color' && (count($pValues) !== count($qValues) || array_diff($pValues, $qValues))) {
                        $partialColor = true;
                        $reasons[] = 'partial_color_evidence';
                    }
                    $reasons[] = $dim . '_agrees'; $matchedVariant = true;
                    if ($dim === 'size') $sizeMatch = true;
                }
            } elseif ($pValues || $qValues) {
                $missingVariant = true;
            }
        }
        if ($missingVariant) $reasons[] = 'missing_variant_corroboration';
        if ($requiresSize && !$sizeMatch) $reasons[] = 'shoe_size_not_confirmed';
        if ($partialColor) $reasons[] = 'color_evidence_not_exact';
        if ($eanEqual) $reasons[] = 'same_valid_gtin';
        if ($brandMatch) $reasons[] = 'brand_agrees';
        if ($qE['value'] !== null && !$qE['reliable']) $reasons[] = 'public_ean_derived_or_unverified';
        $ambiguous = (bool)($context['ambiguous_public_ean'] ?? false);
        if ($ambiguous) $reasons[] = 'ean_reused_for_different_variants_in_public_store';
        $titleOverlap = self::overlap((string)($private['Title'] ?? ''), (string)($public['Title'] ?? ''));
        $categoryOverlap = self::overlap((string)($private['CategoryTags'] ?? ''), (string)($public['CategoryTags'] ?? ''));
        $pDistinct = self::distinctiveTitleTokens((string)($private['Title'] ?? ''), (string)($pa['#brand#'][0] ?? ''));
        $qDistinct = self::distinctiveTitleTokens((string)($public['Title'] ?? ''), (string)($qa['#brand#'][0] ?? ''));
        $distinctiveOverlap = $pDistinct && $qDistinct && (bool)array_intersect($pDistinct, $qDistinct);
        if (!$distinctiveOverlap) $reasons[] = 'no_shared_distinctive_title_terms';
        $decision = 'none';
        if (!$hardStop) {
            // For an automatic connection require matching observed variant evidence, if supplied;
            // and some title corroboration, plus an unambiguous source identifier.
            if ($eanEqual && $brandMatch && $pE['reliable'] && $qE['reliable']
                && !$ambiguous && !$missingVariant && !$partialColor && $matchedVariant && $distinctiveOverlap
                && (!$requiresSize || $sizeMatch) && $titleOverlap >= 0.2) {
                $decision = 'connection';
            } elseif ($eanEqual && $brandMatch) {
                $decision = 'suggestion';
            } elseif ($pE['value'] === null || $qE['value'] === null) {
                if ($brandMatch && $titleOverlap >= 0.60 && !$missingVariant) {
                    $decision = 'suggestion';
                    $reasons[] = 'strong_title_overlap';
                }
            }
        }
        // Ranking hint only. It is not a model probability and was not tuned on labeled data.
        $rank = ($eanEqual ? 100 : 0) + ($brandMatch ? 20 : 0)
            + ($matchedVariant ? 10 : 0) + (int)round($titleOverlap * 10) + (int)round($categoryOverlap * 3);
        return [
            'private_id' => self::identity($private), 'public_id' => self::identity($public),
            'decision' => $decision, 'rank_score' => $rank,
            'reasons' => array_values(array_unique($reasons)),
            'public_ean_evidence' => $qE,
            'title_overlap' => round($titleOverlap, 3), 'category_overlap' => round($categoryOverlap, 3),
        ];
    }

    public static function suggestions(array $decisions, int $limit = 5): array
    {
        $unique = [];
        foreach ($decisions as $decision) {
            if ($decision['decision'] !== 'suggestion') continue;
            $key = $decision['public_id'];
            if (!isset($unique[$key]) || $decision['rank_score'] > $unique[$key]['rank_score']) $unique[$key] = $decision;
        }
        $suggestions = array_values($unique);
        usort($suggestions, fn(array $a, array $b): int => ($b['rank_score'] <=> $a['rank_score']) ?: strcmp($a['public_id'], $b['public_id']));
        return array_slice($suggestions, 0, max(0, $limit));
    }
}
