<?php
declare(strict_types=1);
require __DIR__ . '/../src/MatchingWorkflow.php';
use LengowExample\MatchingWorkflow;

$data = json_decode(file_get_contents(__DIR__ . '/../fixtures/synthetic-products.json'), true, 512, JSON_THROW_ON_ERROR);
$workflow = new MatchingWorkflow($data['public'], $data['followed_public_stores']);
$result = $workflow->match($data['private'], 5);
echo "Synthetic end-to-end product matching demo\n";
echo "Eligible public stores: ", implode(', ', $data['followed_public_stores']), "\n";
echo "Candidate records evaluated: ", $result['candidates_evaluated'], "\n\n";
foreach (['connections','suggestions'] as $group) {
    echo strtoupper($group), ': ', count($result[$group]), "\n";
    foreach ($result[$group] as $match) {
        echo '  - ', $match['public_snapshot']['Code'], ' (', $match['public_snapshot']['SubCode'], ')';
        echo ' | score=', $match['rank_score'], ' | why=', implode(',', $match['reasons']), "\n";
    }
    echo "\n";
}
