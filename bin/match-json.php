<?php
declare(strict_types=1);
require __DIR__ . '/../src/MatchingWorkflow.php';
use LengowExample\MatchingWorkflow;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/match-json.php path/to/small-local-sample.json [max_suggestions]\n");
    exit(2);
}
try {
    $contents = file_get_contents($argv[1]);
    if ($contents === false) throw new RuntimeException('Cannot read JSON input file');
    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!isset($data['private'], $data['public'], $data['followed_public_stores'])
        || !is_array($data['private']) || !is_array($data['public']) || !is_array($data['followed_public_stores'])) {
        throw new InvalidArgumentException('Input needs private (object), public (array), followed_public_stores (array)');
    }
    $limit = isset($argv[2]) ? max(0, (int)$argv[2]) : 5;
    $result = (new MatchingWorkflow($data['public'], $data['followed_public_stores']))->match($data['private'], $limit);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Error: " . $error->getMessage() . "\n");
    exit(1);
}
