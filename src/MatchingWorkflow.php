<?php
declare(strict_types=1);

namespace LengowExample;

require_once __DIR__ . '/Matcher.php';

/** In-memory indexed vertical slice. Production indexes would be external and incrementally maintained. */
final class MatchingWorkflow
{
    private array $products = [];
    private array $eanIndex = [];
    private array $brandTokenIndex = [];
    private array $ambiguousEan = [];

    /** @param string[] $followedStoreIds Explicit public stores this customer follows. */
    public function __construct(array $publicProducts, array $followedStoreIds)
    {
        $allowed = array_fill_keys(array_map('strval', $followedStoreIds), true);
        $variantsByStoreAndEan = [];
        foreach ($publicProducts as $public) {
            $store = (string)($public['StoreID'] ?? '');
            if (!isset($allowed[$store]) || !Matcher::active($public)) continue;
            $id = Matcher::identity($public);
            if (isset($this->products[$id])) continue; // same composite source identity
            $this->products[$id] = $public;
            $attrs = Matcher::attributes((string)($public['Attributes'] ?? ''));
            $ean = Matcher::eanEvidence($attrs);
            if ($ean['value'] !== null) {
                $this->eanIndex[$ean['value']][$id] = true;
                $key = json_encode([$store, $ean['value']], JSON_THROW_ON_ERROR);
                $variants = Matcher::variants($public, $attrs);
                foreach ($variants as $dimension => $values) {
                    if (!$values) continue;
                    if ($dimension === 'color') {
                        sort($values);
                        $variantsByStoreAndEan[$key][$dimension][implode('+', $values)] = true;
                    } else {
                        foreach ($values as $value) $variantsByStoreAndEan[$key][$dimension][$value] = true;
                    }
                }
            }
            $brand = Matcher::brandEvidence($attrs);
            if ($brand['value'] === null) continue;
            foreach (Matcher::tokens((string)($public['Title'] ?? '')) as $token) {
                if (strlen($token) < 3) continue;
                $this->brandTokenIndex[$brand['value']][$token][$id] = true;
            }
        }
        foreach ($variantsByStoreAndEan as $key => $dimensions) {
            foreach ($dimensions as $name => $values) {
                // A color signature is the full set on ONE product: 'pink+green' is one variant.
                // Different signatures across products indicate an EAN reused for other variants.
                if (count($values) > 1) $this->ambiguousEan[$key] = true;
            }
        }
    }

    /** An intentionally bounded lookup: shared EAN first, then rare brand-scoped title tokens. */
    public function match(array $private, int $suggestionLimit = 5, int $titleCandidateLimit = 100): array
    {
        if (!Matcher::active($private)) return ['connections'=>[], 'suggestions'=>[], 'candidates_evaluated'=>0];
        $attrs = Matcher::attributes((string)($private['Attributes'] ?? ''));
        $ean = Matcher::eanEvidence($attrs);
        $ids = [];
        if ($ean['value'] !== null) {
            foreach ($this->eanIndex[$ean['value']] ?? [] as $id => $_) $ids[$id]['ean'] = true;
        }
        $brand = Matcher::brandEvidence($attrs);
        if ($brand['value'] !== null && $titleCandidateLimit > 0) {
            $postings = [];
            foreach (Matcher::tokens((string)($private['Title'] ?? '')) as $token) {
                if (strlen($token) < 3) continue;
                $list = $this->brandTokenIndex[$brand['value']][$token] ?? [];
                if ($list) $postings[$token] = $list;
            }
            // Lower-frequency terms usually provide better selective retrieval.
            uksort($postings, function ($a, $b) use ($postings): int {
                return (count($postings[$a]) <=> count($postings[$b])) ?: strcmp($a, $b);
            });
            $added = 0;
            foreach ($postings as $list) {
                foreach ($list as $id => $_) {
                    if (isset($ids[$id]['brand_title'])) continue;
                    if ($added >= $titleCandidateLimit) break 2;
                    $ids[$id]['brand_title'] = true;
                    $added++;
                }
            }
        }
        $connections = []; $decisions = [];
        foreach ($ids as $id => $methods) {
            $public = $this->products[$id];
            $qa = Matcher::attributes((string)($public['Attributes'] ?? ''));
            $qEan = Matcher::eanEvidence($qa);
            $ambiguous = false;
            if ($qEan['value'] !== null) {
                $key = json_encode([(string)$public['StoreID'], $qEan['value']], JSON_THROW_ON_ERROR);
                $ambiguous = isset($this->ambiguousEan[$key]);
            }
            $result = Matcher::evaluate($private, $public, ['ambiguous_public_ean'=>$ambiguous]);
            $result['retrieval_methods'] = array_keys($methods);
            $result['matching_method'] = in_array('ean', array_keys($methods), true)
                ? (($qEan['reliable'] ?? false) ? 'canonical_ean' : 'derived_ean') : 'brand_title';
            $result['evaluated_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
            $result['public_observed_at'] = ($public['uDate'] ?? null) ?: null;
            if ($result['decision'] === 'suggestion') $result['review_status'] = 'pending';
            $result['public_snapshot'] = [
                'StoreID'=>(string)($public['StoreID'] ?? ''),
                'Code'=>(string)($public['Code'] ?? ''),
                'SubCode'=>(string)($public['SubCode'] ?? ''),
                'Title'=>(string)($public['Title'] ?? ''),
                'SubTitle'=>(string)($public['SubTitle'] ?? ''),
                'CategoryTags'=>(string)($public['CategoryTags'] ?? ''),
                'brand_values'=>$qa['#brand#'] ?? [],
                'URL'=>(string)($public['URL'] ?? ''),
            ];
            if ($result['decision'] === 'connection') $connections[$id] = $result;
            elseif ($result['decision'] === 'suggestion') $decisions[] = $result;
        }
        ksort($connections, SORT_STRING);
        return [
            'connections'=>array_values($connections),
            'suggestions'=>Matcher::suggestions($decisions, $suggestionLimit),
            'candidates_evaluated'=>count($ids),
        ];
    }
}
