<?php
declare(strict_types=1);

namespace LengowExample;

use RuntimeException;
require_once __DIR__ . '/CsvInput.php';
require_once __DIR__ . '/MatchingWorkflow.php';

/**
 * A bounded, stream-based adapter for Lengow's original CSV schema.
 * It scans public rows once, retains only EAN hits and best brand/title hits for
 * a small selected private sample, then reuses the unchanged MatchingWorkflow.
 * This is exploratory processing, NOT a production index or full-dataset benchmark.
 */
final class CsvSampleRunner
{
    private const PRODUCT_FIELDS = [
        'StoreID', 'Code', 'SubCode', 'Title', 'SubTitle', 'CategoryTags',
        'Attributes', 'Active', 'uDate', 'URL',
    ];

    /** @param array<string,mixed> $options */
    public static function run(string $privatePath, ?string $privateMember,
                               string $publicPath, ?string $publicMember, array $options): array
    {
        $privateLimit = (int)($options['limit'] ?? 10);
        $scanLimit = (int)($options['scan_limit'] ?? 5000);
        $titleLimit = (int)($options['title_candidates'] ?? 25);
        $maxEan = (int)($options['max_ean_candidates'] ?? 4000);
        $suggestionLimit = (int)($options['suggestions'] ?? 5);
        $privateCode = $options['private_code'] ?? null;
        $allStores = ($options['stores'] ?? '') === 'all';
        $allowedStores = $allStores ? [] : array_fill_keys(explode(',', (string)($options['stores'] ?? '')), true);
        if ($privateLimit < 1 || $privateLimit > 100) throw new RuntimeException('--limit must be from 1 to 100');
        if ($scanLimit < 0 || $titleLimit < 0 || $titleLimit > 100 || $maxEan < 1 || $suggestionLimit < 0 || $suggestionLimit > 100) {
            throw new RuntimeException('Invalid scan, title, EAN or suggestion limit');
        }
        if (!$allStores && array_key_exists('', $allowedStores)) {
            throw new RuntimeException('Specify a real --stores=ID[,ID] list, or --stores=all for exploration');
        }

        $privateInput = new CsvInput($privatePath, $privateMember);
        try {
            $privateInput->requireColumns(['StoreID','Code','SubCode','Title','SubTitle','Attributes','CategoryTags','Active']);
            $privates = [];
            while (($row = $privateInput->next()) !== null) {
                if ($privateCode !== null && (string)$row['Code'] !== (string)$privateCode) continue;
                if (!Matcher::active($row)) continue;
                $privates[] = self::compact($row);
                if (count($privates) >= $privateLimit) break;
            }
            $privateScanned = $privateInput->rowsRead;
        } finally {
            $privateInput->close();
        }
        if (!$privates) throw new RuntimeException('No active private products selected');

        $byEan = []; $byBrandToken = []; $privateEans = [];
        foreach ($privates as $i => $private) {
            $attrs = Matcher::attributes($private['Attributes']);
            $ean = Matcher::eanEvidence($attrs);
            $privateEans[$i] = $ean['value'];
            if ($ean['value'] !== null) $byEan[$ean['value']][$i] = true;
            $brand = Matcher::brandEvidence($attrs);
            if ($brand['value'] === null || $titleLimit === 0) continue;
            foreach (Matcher::distinctiveTitleTokens($private['Title'], (string)($attrs['#brand#'][0] ?? '')) as $token) {
                if (strlen($token) >= 3) $byBrandToken[$brand['value']][$token][$i] = true;
            }
        }
        $publicInput = new CsvInput($publicPath, $publicMember);
        $eanCandidates = []; $titlePools = []; $observedStores = [];
        $seen = 0; $eligibleRows = 0; $eanHits = 0; $complete = true;
        try {
            $publicInput->requireColumns(['StoreID','Code','SubCode','Title','SubTitle','Attributes','CategoryTags','Active']);
            while (true) {
                if ($scanLimit !== 0 && $seen >= $scanLimit) { $complete = false; break; }
                $row = $publicInput->next();
                if ($row === null) break;
                $seen++;
                $store = (string)$row['StoreID'];
                if (!$allStores && !isset($allowedStores[$store])) continue;
                if (!Matcher::active($row)) continue;
                $eligibleRows++;
                $attrs = Matcher::attributes((string)$row['Attributes']);
                $ean = Matcher::eanEvidence($attrs);
                $id = Matcher::identity($row);
                if ($ean['value'] !== null && isset($byEan[$ean['value']])) {
                    $eanHits++;
                    if (!isset($eanCandidates[$id])) {
                        if (count($eanCandidates) >= $maxEan) {
                            throw new RuntimeException('EAN candidate cap exceeded. Reduce --limit or narrow --stores; no incomplete EAN grouping will be classified.');
                        }
                        $eanCandidates[$id] = self::compact($row);
                    }
                    $observedStores[$store] = true;
                }
                // Title candidates are needed primarily where one side lacks an EAN.
                $brand = Matcher::brandEvidence($attrs);
                $brandValue = $brand['value'];
                if ($titleLimit === 0 || $brandValue === null || !isset($byBrandToken[$brandValue])) continue;
                $matches = [];
                foreach (Matcher::tokens((string)$row['Title']) as $token) {
                    foreach ($byBrandToken[$brandValue][$token] ?? [] as $privateIndex => $_) {
                        $matches[$privateIndex] = true;
                    }
                }
                if (!$matches) continue;
                foreach ($matches as $privateIndex => $_) {
                    if ($privateEans[$privateIndex] !== null && $ean['value'] !== null) continue;
                    $score = Matcher::overlap($privates[$privateIndex]['Title'], (string)$row['Title']);
                    if ($score < 0.60) continue;
                    $pool =& $titlePools[$privateIndex];
                    if (!isset($pool)) $pool = [];
                    if (isset($pool[$id])) { unset($pool); continue; }
                    if (count($pool) < $titleLimit) {
                        $pool[$id] = ['product'=>self::compact($row), 'score'=>$score];
                        $observedStores[$store] = true;
                    } else {
                        // Deterministically retain the best K, independent of public row order.
                        $worstId = null; $worstScore = INF;
                        foreach ($pool as $oldId => $entry) {
                            if ($entry['score'] < $worstScore || ($entry['score'] === $worstScore && strcmp($oldId, (string)$worstId) > 0)) {
                                $worstScore = $entry['score']; $worstId = $oldId;
                            }
                        }
                        if ($score > $worstScore || ($score === $worstScore && strcmp($id, (string)$worstId) < 0)) {
                            unset($pool[$worstId]);
                            $pool[$id] = ['product'=>self::compact($row), 'score'=>$score];
                            $observedStores[$store] = true;
                        }
                    }
                    unset($pool);
                }
            }
        } finally {
            $publicInput->close();
        }

        $matches = []; $totalCandidates = 0; $totalConnections = 0; $totalSuggestions = 0;
        foreach ($privates as $i => $private) {
            $candidateRows = [];
            $ean = $privateEans[$i];
            if ($ean !== null) {
                foreach ($eanCandidates as $id => $row) {
                    $evidence = Matcher::eanEvidence(Matcher::attributes($row['Attributes']));
                    if ($evidence['value'] === $ean) $candidateRows[$id] = $row;
                }
            }
            foreach ($titlePools[$i] ?? [] as $id => $entry) $candidateRows[$id] = $entry['product'];
            // For --stores=all only, use the observed store IDs; the actual customer follow graph is unknown.
            $stores = $allStores ? array_keys($observedStores) : array_keys($allowedStores);
            $result = (new MatchingWorkflow(array_values($candidateRows), $stores))->match($private, $suggestionLimit, $titleLimit);
            if (!$complete && $result['connections']) {
                // A partial public scan cannot rule out later reused EANs/variant conflicts.
                $downgraded = [];
                foreach ($result['connections'] as $record) {
                    $record['decision'] = 'suggestion';
                    $record['review_status'] = 'pending';
                    $record['reasons'][] = 'public_scan_incomplete';
                    $downgraded[] = $record;
                }
                $result['suggestions'] = Matcher::suggestions(array_merge($result['suggestions'], $downgraded), $suggestionLimit);
                $result['connections'] = [];
            }
            $totalCandidates += $result['candidates_evaluated'];
            $totalConnections += count($result['connections']);
            $totalSuggestions += count($result['suggestions']);
            $matches[] = [
                'private_id'=>Matcher::identity($private),
                'private_title'=>$private['Title'],
                'private_subtitle'=>$private['SubTitle'],
                'result'=>$result,
            ];
        }
        return [
            'run_mode'=>'local_exploratory_sample',
            'store_scope'=>$allStores ? 'all_public_stores_exploratory_not_verified_followed' : 'explicit_store_ids',
            'scan_complete'=>$complete,
            'summary'=>[
                'private_rows_scanned'=>$privateScanned,
                'private_products_selected'=>count($privates),
                'public_rows_scanned'=>$seen,
                'public_active_rows_in_scope'=>$eligibleRows,
                'public_ean_candidate_rows_found'=>$eanHits,
                'public_ean_candidates_retained'=>count($eanCandidates),
                'candidates_evaluated'=>$totalCandidates,
                'connections'=>$totalConnections,
                'suggestions'=>$totalSuggestions,
                'peak_php_memory_bytes'=>memory_get_peak_usage(true),
            ],
            'matches'=>$matches,
        ];
    }

    /** Reduce memory and remove malformed source byte sequences only from retained records. */
    private static function compact(array $row): array
    {
        $compact = [];
        foreach (self::PRODUCT_FIELDS as $field) {
            $value = (string)($row[$field] ?? '');
            if (!preg_match('//u', $value) && function_exists('iconv')) {
                $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
                if ($clean !== false) $value = $clean;
            }
            $compact[$field] = $value;
        }
        return $compact;
    }
}
