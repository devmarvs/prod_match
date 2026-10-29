<?php
declare(strict_types=1);
require __DIR__.'/../src/CsvSampleRunner.php';

use LengowExample\CsvInput;
use LengowExample\CsvSampleRunner;

$passed = 0;
function confirm(bool $ok, string $message): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAILED: '.$message);
    echo "PASS: {$message}\n";
    $passed++;
}
function makeCsv(string $filename, array $rows, bool $bom=false): void {
    $fp = fopen($filename,'wb');
    if ($bom) fwrite($fp,"\xEF\xBB\xBF");
    $keys = array_keys($rows[0]);
    fputcsv($fp,$keys,',','"','');
    foreach ($rows as $row) fputcsv($fp,array_values($row),',','"','');
    fclose($fp);
}
$dir = sys_get_temp_dir().'/lengow-csv-test-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
try {
    $data = json_decode(file_get_contents(__DIR__.'/../fixtures/synthetic-products.json'),true,512,JSON_THROW_ON_ERROR);
    $private = $data['private'];
    $public = $data['public'];
    $private['Title'] = $private['Title'];
    $public[] = ['StoreID'=>'9003','Code'=>'UNRELATED','SubCode'=>'','Title'=>'Unrelated brand widget',
        'SubTitle'=>'', 'CategoryTags'=>'','Attributes'=>'#brand#=>Other Brand','Active'=>'1'];
    $privateFile=$dir.'/private.csv'; $publicFile=$dir.'/public.csv';
    makeCsv($privateFile,[$private], true);
    makeCsv($publicFile,$public);
    $reader = new CsvInput($privateFile);
    $reader->requireColumns(['StoreID','Title','Attributes']);
    $row = $reader->next();
    confirm($row !== null && $row['Title'] === $private['Title'], 'CSV with UTF-8 BOM parses correctly');
    confirm($reader->next() === null, 'CSV detects EOF');
    $reader->close();
    $args=['limit'=>1, 'stores'=>'9002,9003', 'scan_limit'=>0, 'suggestions'=>5, 'title_candidates'=>25];
    $result = CsvSampleRunner::run($privateFile,null,$publicFile,null,$args);
    confirm($result['scan_complete'], 'complete CSV scan recorded');
    confirm($result['summary']['connections']===1, 'exactly one safe connection from synthetic CSV');
    confirm($result['summary']['suggestions']===2, 'inferred and no-EAN suggestions from synthetic CSV');
    $codes = array_column(array_column($result['matches'][0]['result']['suggestions'],'public_snapshot'),'Code');
    confirm(in_array('NO-EAN-43',$codes,true), 'candidate retrieval finds no-EAN suggestion');
    confirm(!in_array('DEDUCED-42',$codes,true), 'incorrect shoe size excluded from CSV run');
    $only9003 = CsvSampleRunner::run($privateFile,null,$publicFile,null,[...$args,'stores'=>'9003']);
    confirm($only9003['summary']['connections']===0, 'CSV reader respects allowed-store scope');
    $partial = CsvSampleRunner::run($privateFile,null,$publicFile,null,[...$args,'scan_limit'=>1]);
    confirm(!$partial['scan_complete'], 'early scan reports incomplete');
    confirm($partial['summary']['connections']===0, 'incomplete public scan does not auto-connect');
    confirm(in_array('public_scan_incomplete',$partial['matches'][0]['result']['suggestions'][0]['reasons'],true), 'downgrade explains why decision is provisional');
    $none = CsvSampleRunner::run($privateFile,null,$publicFile,null,[...$args,'suggestions'=>0]);
    confirm($none['summary']['suggestions']===0, 'configurable suggestion limit zero');
    try {
        CsvSampleRunner::run($privateFile,null,$publicFile,null,[...$args,'max_ean_candidates'=>1]);
        throw new RuntimeException('No cap exception');
    } catch (RuntimeException $e) {
        confirm(str_contains($e->getMessage(),'cap exceeded'), 'EAN candidate safety cap fails closed');
    }
    $private['Code']='P2';
    $private['Title']='Hoka Torrent 4, size 43 blue';
    makeCsv($privateFile,[$private]);
    $result2 = CsvSampleRunner::run($privateFile,null,$publicFile,null,$args);
    confirm($result2['summary']['private_products_selected']===1, 'quoted comma in title parses correctly');
    $private['Title']="Hoka Torrent 4 shoe size 43 blue\nnew line";
    makeCsv($privateFile,[$private]);
    $reader = new CsvInput($privateFile);
    confirm($reader->next()['Title']===$private['Title'], 'quoted newline in CSV is parsed as a field');
    $reader->close();
    $private['Title'] = 'Hoka Torrent 4 shoe size 43 blue';
    makeCsv($privateFile,[$private]);
    // A different private code can be selected without loading every private row into memory.
    $second = $private;
    $second['Code'] = 'P3';
    makeCsv($privateFile, [$private, $second]);
    $choose = CsvSampleRunner::run($privateFile,null,$publicFile,null,[...$args, 'private_code'=>'P3']);
    confirm($choose['summary']['private_products_selected'] === 1 &&
        str_contains($choose['matches'][0]['private_id'], 'P3'), 'exact private Code selection works');
    try {
        CsvSampleRunner::run($privateFile,null,$publicFile,null,[...$args,'private_code'=>'UNKNOWN']);
        throw new RuntimeException('No missing private exception');
    } catch (RuntimeException $e) {
        confirm(str_contains($e->getMessage(),'No active private products'), 'unknown private Code fails clearly');
    }
    try {
        CsvSampleRunner::run($privateFile,null,$publicFile,null,[...$args,'stores'=>'']);
        throw new RuntimeException('No empty store exception');
    } catch (RuntimeException $e) {
        confirm(str_contains($e->getMessage(),'--stores'), 'missing store scope is rejected');
    }
    $missingHeader = $dir . '/missing.csv';
    makeCsv($missingHeader, [['Title'=>'Product', 'Code'=>'Q']]);
    try {
        CsvSampleRunner::run($privateFile,null,$missingHeader,null,[...$args]);
        throw new RuntimeException('No missing column exception');
    } catch (RuntimeException $e) {
        confirm(str_contains($e->getMessage(),'missing columns'), 'missing CSV columns are rejected');
    }
} finally {
    foreach (glob($dir.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($dir);
}
echo "CSV integration: {$passed} checks passed.\n";
