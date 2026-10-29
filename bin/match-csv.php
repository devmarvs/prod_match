<?php
declare(strict_types=1);

require __DIR__ . '/../src/CsvSampleRunner.php';

use LengowExample\CsvSampleRunner;

function usage(): void
{
    $message = <<<'HELP'
Usage (original ZIP, no extraction needed if ZipArchive or unzip is available):
  php bin/match-csv.php --zip=/path/use-case-data.zip --stores=28349 --limit=10

Usage (previously extracted CSV files):
  php bin/match-csv.php --private=/path/private.csv --public=/path/public.csv --stores=28349 --limit=10

Options:
  --stores=28349,OTHER  Explicit eligible public StoreID values (required)
  --stores=all          Exploratory across all available stores; NOT actual customer follow mapping
  --limit=10            Maximum active private products selected (1..100)
  --private-code=CODE   Select a private product by its exact Code value
  --scan-limit=5000     Public rows to scan (default 5000; 0 = whole supplied CSV)
  --suggestions=5       Maximum suggestions per private product (0..100)
  --title-candidates=25  Maximum retained title candidates per private product (0..100)
  --max-ean-candidates=4000  Abort if too many EAN candidates (safety/memory guard)
  --report=FILE         Save the JSON report to FILE rather than stdout

If --scan-limit stops early, all provisional automatic connections are downgraded
into suggestions (or omitted by the suggestion cap) for safety.
Raw customer/product records must not be committed to public Git repositories.
HELP;
    fwrite(STDERR, $message . "\n");
}

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') { usage(); exit(0); }
        if (!preg_match('/^--([a-z-]+)=(.*)$/s', $argument, $match)) {
            throw new InvalidArgumentException("Invalid argument: {$argument}. Use --help.");
        }
        $key = str_replace('-', '_', $match[1]);
        if (!in_array($key, ['zip','private','public','stores','limit','private_code','scan_limit',
            'suggestions','title_candidates','max_ean_candidates','report'], true)) {
            throw new InvalidArgumentException("Unknown option: --{$match[1]}");
        }
        if (array_key_exists($key, $options)) throw new InvalidArgumentException("Duplicate option: --{$match[1]}");
        $options[$key] = $match[2];
    }
    if (empty($options['stores'])) throw new InvalidArgumentException('--stores is required');
    if (isset($options['zip']) === (isset($options['private']) || isset($options['public']))) {
        throw new InvalidArgumentException('Provide --zip OR both --private and --public');
    }
    if (!isset($options['zip']) && (!isset($options['private']) || !isset($options['public']))) {
        throw new InvalidArgumentException('Both --private and --public are required for CSV input');
    }
    foreach (['limit','scan_limit','suggestions','title_candidates','max_ean_candidates'] as $number) {
        if (isset($options[$number]) && !preg_match('/^\d+$/D', $options[$number])) {
            throw new InvalidArgumentException("--" . str_replace('_','-',$number) . ' must be a nonnegative integer');
        }
    }
    $zip = $options['zip'] ?? null;
    $privatePath = $zip ?? $options['private'];
    $publicPath = $zip ?? $options['public'];
    $result = CsvSampleRunner::run(
        $privatePath, $zip ? 'data/use-case-private_products-intersport.csv' : null,
        $publicPath, $zip ? 'data/use-case-public_products-final.csv' : null,
        $options
    );
    $json = json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if (isset($options['report'])) {
        if ($options['report'] === '') throw new InvalidArgumentException('--report requires a file path');
        $written = @file_put_contents($options['report'], $json . "\n");
        if ($written === false) throw new RuntimeException('Unable to write JSON report: '.$options['report']);
        fwrite(STDERR, "Saved report: {$options['report']}\n");
        fwrite(STDERR, 'Rows scanned: '.$result['summary']['public_rows_scanned']
            . ', selected: '.$result['summary']['private_products_selected']
            . ', connections: '.$result['summary']['connections']
            . ', suggestions: '.$result['summary']['suggestions'] . "\n");
    } else {
        echo $json, "\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    usage();
    exit(1);
}
