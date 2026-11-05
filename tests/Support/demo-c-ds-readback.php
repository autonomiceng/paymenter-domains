<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Registered paired protocol harness supplies bootstrap.

use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Ds, DsRegistryPolicy};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DsMapping, Outcome};

expect(method_exists(Ds::class, 'readback'), 'independent DS tuple readback exists');
expect(method_exists(Ds::class, 'acknowledgement'), 'DS acknowledgement separate from readback');
$row = ['key_tag' => 12345, 'algorithm' => 13, 'digest_type' => 2, 'digest' => str_repeat('AB', 32)];
$sibling = array_replace($row, ['key_tag' => 23456]);
$policy = new DsRegistryPolicy(['format'=>1, 'entries'=>['example'=>[
    'suffix'=>'example', 'input'=>'ds', 'algorithms'=>[13], 'digest_types'=>[2],
    'combinations'=>[['algorithm'=>13, 'digest_type'=>2]], 'source'=>'synthetic', 'version'=>'rule-1',
    'reviewed_at'=>'2026-11-01T00:00:00+00:00', 'valid_until'=>'2026-12-01T00:00:00+00:00',
    'expiry_policy'=>'refuse']]], new DateTimeImmutable('2026-11-04T12:00:00+00:00'));
$add = DsMapping::add('example.example', $row, $policy);
$delete = DsMapping::delete('example.example', $row, $policy);
$wire = static fn (string $operation, array $reply): string => json_encode([
    'request' => ['operation' => $operation], 'reply' => ['code' => 300, 'detail' => 'synthetic success'] + $reply,
], JSON_THROW_ON_ERROR);
$list = static fn (mixed $rows): string => $wire('dnsSecListRecords', ['ds_record' => $rows]);
foreach ([$add, $delete] as $mapping) {
    $ack = $wire($mapping->command, []);
    $result = Ds::acknowledgement($ack, $mapping);
    same(Outcome::Success, $result->outcome, 'documented acknowledgement accepted');
    same([], $result->catalogueRows(), 'ack retains no state proof or provider diagnostic');
    expect(!str_contains(json_encode($result), 'synthetic success'), 'ack sanitized');
    same(false, Ds::readback($mapping, $list([$row]), $ack), 'ack cannot substitute for independent readback');
    foreach ([$wire('dnsSecListRecords', []), $wire($mapping->command, ['ds_record' => []]),
        str_replace('300', '301', $ack), str_replace('300', '999', $ack), '{'] as $bad) {
        same(Outcome::Unknown, Ds::acknowledgement($bad, $mapping)->outcome, 'wrong/ambiguous acknowledgement unknown');
    }
}
same(Outcome::Unknown, Ds::acknowledgement($wire('dnsSecAddRecord', []), $delete)->outcome, 'command must match frozen descriptor');
same(Outcome::Unknown, Ds::acknowledgement($list([]), DsMapping::listRecords('example.example'))->outcome, 'list is not mutation acknowledgement');
same(true, Ds::readback($delete, $list([$row]), $list([])), 'delete final tuple proven by explicit empty readback');
foreach (['{"request":{"operation":"dnsSecListRecords"},"reply":{"code":300,"detail":"success","ds_record":[]},"more":true}',
    '{"request":{"operation":"dnsSecListRecords","more":true},"reply":{"code":300,"detail":"success","ds_record":[]}}'] as $after) {
    same(false, Ds::readback($delete, $list([$row]), $after), 'extra root/request metadata cannot prove final deletion');
    expect(Ds::inventory($after)['state'] !== 'complete', 'extra root/request metadata withholds inventory completeness');
}
$withIp = '{"request":{"operation":"dnsSecListRecords","ip":"192.0.2.1"},"reply":{"code":300,"detail":"success","ds_record":[]}}';
same(['state' => 'complete', 'records' => []], Ds::inventory($withIp), 'documented request IP permits complete empty list');
same(true, Ds::readback($delete, $list([$row]), $withIp), 'documented request IP preserves final deletion proof');
same(true, Ds::readback($delete, $list([$sibling, $row]), $list([$sibling])), 'delete exact tuple preserving siblings');
same(true, Ds::readback($add, $list([]), $list([$row])), 'add independently observed');
same(true, Ds::readback($add, $list([$sibling]), $list([$row, $sibling])), 'add ignores order and preserves siblings');
$alias = array_replace($row, ['key_tag' => '12345', 'algorithm' => '13', 'digest_type' => '2', 'digest' => strtolower($row['digest'])]);
same(true, Ds::readback($delete, $list([$alias]), $list([])), 'readback compares canonical full tuples');
foreach ([$list([$row]), $list([$sibling]), $list((object) []), $list(null),
    $list([array_replace($row, ['digest' => 'bad'])]), $list([$sibling, $sibling])] as $after) {
    same(false, Ds::readback($delete, $list([$row]), $after), 'unremoved/partial/extra sibling state cannot prove delete');
}
foreach ([$list([]), $list([$sibling]), $list([$row, $row]), $list((object) []), $list([['digest' => 'bad']])] as $before) {
    same(false, Ds::readback($delete, $before, $list([])), 'delete requires complete baseline containing exact tuple once');
}
foreach ([['key_tag' => 12346], ['algorithm' => 14], ['digest_type' => 4, 'digest' => str_repeat('AB', 48)],
    ['digest' => str_repeat('CD', 32)]] as $delta) {
    $other = array_replace($row, $delta);
    same(false, Ds::readback($delete, $list([$other]), $list([])), 'every tuple field participates in delete proof');
    same(false, Ds::readback($add, $list([]), $list([$other])), 'every tuple field participates in add proof');
}
same(false, Ds::readback($delete, $list([$row, $sibling]), $list([])), 'sibling loss cannot prove isolated delete');
same(false, Ds::readback($add, $list([$sibling]), $list([$row])), 'sibling loss cannot prove isolated add');
same(false, Ds::readback($add, $list([$row]), $list([$row])), 'already-present tuple not evidence of this add');
same(false, Ds::readback($add, $list([]), $list([])), 'unchanged list not add proof');
same(false, Ds::readback(DsMapping::listRecords('example.example'), $list([]), $list([])), 'list has no write delta');
echo "NameSilo DS acknowledgement/readback tests passed\n";
