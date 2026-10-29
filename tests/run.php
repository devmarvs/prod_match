<?php
declare(strict_types=1);
require __DIR__ . '/../src/MatchingWorkflow.php';
use LengowExample\Matcher;
use LengowExample\MatchingWorkflow;

$checks = 0;
function check(bool $valid, string $message): void {
    global $checks;
    if (!$valid) throw new RuntimeException("FAIL: $message");
    $checks++;
    echo "PASS: $message\n";
}
function product(string $code, string $size = '43', string $brand = 'Hoka One One', string $title = 'Hoka Torrent 4 shoe size 43 blue', string $ean = '04012345001235', string $store = '9002'): array {
    return ['StoreID'=>$store, 'Code'=>$code, 'SubCode'=>'', 'Title'=>$title, 'SubTitle'=>$size,
        'CategoryTags'=>'shoes|running', 'Attributes'=>"EAN=>$ean"."[|]#brand#=>$brand", 'Active'=>'1'];
}
$p = product('P', '43', 'HOKA ONE ONE', 'Hoka Torrent 4 shoe size 43 blue', '04012345001235', '9001');
$q = product('Q');
check(Matcher::evaluate($p, $q)['decision'] === 'connection', 'verified EAN and compatible brand, title and size: connection');
check(Matcher::gtin('04012345001235') === '04012345001235', 'GTIN-14 leading zero retained');
check(Matcher::gtin('04012345001236') === null, 'invalid GTIN checksum rejected');
check(count(Matcher::attributes('REF=>a[|]REF=>b')['REF']) === 2, 'repeated attributes retained');
check(Matcher::normalize('On-Running') === Matcher::normalize('ON RUNNING'), 'simple brand punctuation normalization');
check(Matcher::identity(['StoreID'=>'A|B','Code'=>'C','SubCode'=>'']) !== Matcher::identity(['StoreID'=>'A','Code'=>'B|C','SubCode'=>'']), 'identity encoding cannot collide on separators');
check(Matcher::identity(['StoreID'=>'A','Code'=>'X','SubCode'=>'1']) !== Matcher::identity(['StoreID'=>'A','Code'=>'X','SubCode'=>'2']), 'variant SubCode is part of identity');

$t = $q; $t['SubTitle'] = ''; $t['Title'] = 'Hoka Torrent 4 shoe size 42 blue';
check(Matcher::evaluate($p, $t)['decision'] === 'none', 'size conflict in Title is found even when SubTitle empty');
$t = $q; $t['Title'] = 'Hoka Torrent 4 shoe size 42 blue';
check(Matcher::evaluate($p, $t)['decision'] === 'none', 'conflicting Title and SubTitle size in one record is unsafe');
$t = $q; $t['SubTitle'] = ''; $t['Title'] = 'Hoka Torrent 4 blue';
check(Matcher::evaluate($p, $t)['decision'] === 'suggestion', 'unverified variant not silently promoted into connection');
$t = $p; $t['SubTitle'] = ''; $t['Title'] = 'Hoka Torrent 4 blue';
$u = $q; $u['SubTitle'] = ''; $u['Title'] = 'Hoka Torrent 4 blue';
check(Matcher::evaluate($t, $u)['decision'] === 'suggestion', 'without size evidence on either side, abstain from auto-connection');
$t = $q; $t['Attributes'] = 'EAN_DEDUCTED=>04012345001235[|]#brand#=>Hoka One One';
check(Matcher::evaluate($p, $t)['decision'] === 'suggestion', 'inferred EAN does not auto-connect');
$t['Attributes'] = 'EAN=>04012345001235[|]EAN_DEDUCTED=>04012345001235[|]#brand#=>Hoka One One';
check(Matcher::evaluate($p, $t)['decision'] === 'suggestion', 'promoted inferred EAN provenance preserved');
$t = $q; $t['Attributes'] = 'EAN=>04012345001235[|]#brand#=>Hoka One One[|]#brand#=>ADIDAS';
check(Matcher::evaluate($p, $t)['decision'] === 'none', 'contradictory repeated brand blocks connection');
$t = $q; $t['Active'] = '0';
check(Matcher::evaluate($p, $t)['decision'] === 'none', 'inactive public listing cannot be connected');
$t = $q; $t['Active'] = '-1';
check(Matcher::evaluate($p, $t)['decision'] === 'none', 'negative public activity status excluded');
$t = $q; $t['Attributes'] = 'EAN=>04012345001235[|]#brand#=>ADIDAS';
check(Matcher::evaluate($p, $t)['decision'] === 'none', 'different brand veto');
$t = $q; $t['Attributes'] = 'EAN=>04012345001242[|]#brand#=>Hoka One One';
check(Matcher::evaluate($p, $t)['decision'] === 'none', 'different valid EANs veto');
$t = $q; $t['Attributes'] = 'EAN=>04012345001235[|]EAN_DEDUCTED=>04012345001242[|]#brand#=>Hoka One One';
check(Matcher::evaluate($p, $t)['decision'] === 'none', 'conflicting EAN provenance values veto');
$t = $q; $t['Attributes'] = 'EAN=>04012345001235[|]EAN_INVALID=>nonsense[|]#brand#=>Hoka One One';
check(Matcher::evaluate($p, $t)['decision'] === 'connection', 'EAN_INVALID diagnostic is not positive identity evidence');
$t = $q; $t['Attributes'] = '#brand#=>Hoka One One';
check(Matcher::evaluate($p, $t)['decision'] === 'suggestion', 'missing EAN with strong title and brand is suggestion');
$t = $q; $t['Attributes'] = 'EAN_INVALID=>04012345001235[|]#brand#=>Hoka One One';
check(Matcher::evaluate($p, $t)['decision'] === 'suggestion', 'EAN_INVALID does not produce EAN-based connection');
$t = $q; $t['Title'] = 'Hoka Torrent 4 shoe size 43 black';
check(Matcher::evaluate($p, $t)['decision'] === 'none', 'different explicitly observed colours veto');
$cp = $p; $cp['Title'] = 'Phone case 128GB size 43 blue';
$cq = $q; $cq['Title'] = 'Phone case 256 GB size 43 blue';
check(Matcher::evaluate($cp, $cq)['decision'] === 'none', 'different storage capacities veto');
$cp['Title'] = 'Trail snack pack of 2 size 43 blue'; $cq['Title'] = 'Trail snack pack of 4 size 43 blue';
check(Matcher::evaluate($cp, $cq)['decision'] === 'none', 'different pack quantities veto');
$t = $q;
check(Matcher::evaluate($p, $t, ['ambiguous_public_ean'=>true])['decision'] === 'suggestion', 'reused EAN in store lowers otherwise confirmed pair to suggestion');

$data = json_decode(file_get_contents(__DIR__.'/../fixtures/synthetic-products.json'), true, 512, JSON_THROW_ON_ERROR);
$workflow = new MatchingWorkflow($data['public'], $data['followed_public_stores']);
$result = $workflow->match($data['private'], 5);
$connCodes = array_column(array_column($result['connections'], 'public_snapshot'), 'Code');
$suggCodes = array_column(array_column($result['suggestions'], 'public_snapshot'), 'Code');
check(in_array('VERIFIED-43', $connCodes, true), 'workflow retrieves safe connection by EAN');
check(!in_array('DEDUCED-42', $connCodes, true) && !in_array('DEDUCED-42', $suggCodes, true), 'workflow rejects wrong-size record despite EAN collision');
check(in_array('DEDUCED-43', $suggCodes, true), 'workflow proposes only matching inferred-EAN size as suggestion');
check(in_array('NO-EAN-43', $suggCodes, true), 'workflow can find missing-EAN record through title and brand index');
check(!in_array('INACTIVE-43', $connCodes, true) && !in_array('INACTIVE-43', $suggCodes, true), 'workflow excludes inactive product');
check(!in_array('UNFOLLOWED-43', $connCodes, true) && !in_array('UNFOLLOWED-43', $suggCodes, true), 'workflow respects followed-store restriction');
check(!in_array('CONFLICTED-43', $connCodes, true) && !in_array('CONFLICTED-43', $suggCodes, true), 'workflow excludes contradictory brands');
check(count($result['suggestions']) <= 5, 'top-K suggestion limit enforced globally');
check(count($workflow->match($data['private'], 1)['suggestions']) === 1, 'configurable suggestion cap operates across stores');
$duplicated = $data['public']; $duplicated[] = $data['public'][0];
$dupResult = (new MatchingWorkflow($duplicated, ['9002','9003']))->match($data['private']);
check(count($dupResult['connections']) === count($result['connections']), 'duplicate identical composite identity does not create duplicate links');
$privateOff = $data['private']; $privateOff['Active'] = '0';
check((new MatchingWorkflow($data['public'], ['9002']))->match($privateOff)['candidates_evaluated'] === 0, 'inactive private records do not trigger matching');
check((new MatchingWorkflow($data['public'], []))->match($data['private'])['candidates_evaluated'] === 0, 'empty followed-store list yields no candidates');
$reused = [product('S43', '43','Hoka One One','Hoka Torrent 4 shoe size 43 blue'),
           product('S42', '42','Hoka One One','Hoka Torrent 4 shoe size 42 blue')];
$reusedResult = (new MatchingWorkflow($reused, ['9002']))->match($p);
check(count($reusedResult['connections']) === 0, 'cross-record reused EAN for different sizes blocks automatic connections');
check(count($reusedResult['suggestions']) === 1, 'reused EAN keeps correct-size candidate as suggestion');
$inTwoStores = [product('S43a', '43', 'Hoka One One','Hoka Torrent 4 shoe size 43 blue', '04012345001235','9002'),
                product('S42b', '42', 'Hoka One One','Hoka Torrent 4 shoe size 42 blue', '04012345001235','9003')];
check(count((new MatchingWorkflow($inTwoStores,['9002','9003']))->match($p)['connections']) === 1, 'EAN reuse in different stores does not create same-store ambiguity');
$manualSuggestions = [
    ['decision'=>'suggestion','public_id'=>'z','rank_score'=>10],
    ['decision'=>'suggestion','public_id'=>'a','rank_score'=>10],
    ['decision'=>'suggestion','public_id'=>'a','rank_score'=>10]
];
check(array_column(Matcher::suggestions($manualSuggestions,5),'public_id') === ['a','z'], 'top-K deduplicates identities and tie-breaks deterministically');

$wrongModel = $q; $wrongModel['Title'] = 'Hoka Speedgoat 5 shoe size 43 blue';
check(Matcher::evaluate($p, $wrongModel)['decision'] === 'suggestion', 'same EAN but incompatible distinctive model title is not auto-connected');
$modelConflict = $q; $modelConflict['Attributes'] .= '[|]#model#=>Speedgoat';
$pModel = $p; $pModel['Attributes'] .= '[|]#model#=>Torrent';
check(Matcher::evaluate($pModel, $modelConflict)['decision'] === 'none', 'explicit mismatched #model# blocks matching');
$attrColor = $q; $attrColor['Attributes'] .= '[|]#color#=>black';
check(Matcher::evaluate($p, $attrColor)['decision'] === 'none', 'colour attribute contradicting title prevents matching');
$multiColor = $q; $multiColor['Title'] = 'Hoka Torrent 4 shoe size 43 blue green';
check(Matcher::evaluate($p, $multiColor)['decision'] === 'suggestion', 'partial overlap of multicolour variants does not auto-connect');
$colorReused = [
    product('CBLUE', '43','Hoka One One','Hoka Torrent 4 shoe size 43 blue'),
    product('CBLACK','43','Hoka One One','Hoka Torrent 4 shoe size 43 black')
];
$colorReusedResult = (new MatchingWorkflow($colorReused, ['9002']))->match($p);
check(count($colorReusedResult['connections']) === 0, 'reused EAN across colour variants in one store blocks automatic connections');
check(count($colorReusedResult['suggestions']) === 1, 'the compatible colour remains a suggestion, not a forced connection');
check((new MatchingWorkflow($data['public'], ['9002','9003']))->match($data['private'], 0)['suggestions'] === [], 'top-K zero suppresses all suggestions');

echo "\n$checks checks passed.\n";
