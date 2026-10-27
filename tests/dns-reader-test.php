<?php
// Synthetic DNS mapping only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsContext,DnsQuery,NativeDnsReader};
use Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader;
$q = new DnsQuery('Example.COM.', 'NS');
$calls = 0; $time = 0.0;
$rows = [['host' => 'EXAMPLE.com.', 'class' => 'IN', 'type' => 'NS', 'ttl' => 300, 'target' => 'NS1.Platform.test.']];
$positiveRows = $rows;
$reader = new NativeDnsReader(function ($name, $type) use (&$calls, &$rows) { $calls++; same('example.com.', $name, 'Absolute query bypasses resolver search suffixes'); same(DNS_NS, $type, 'Exact native mask'); return $rows; });
same(0, $calls, 'Constructor has no I/O');
$ctx = new DnsContext(fn() => $time);
$answer = $reader->read($q, $ctx);
same('rrset', $answer->state, 'Complete native result'); same(['ns1.platform.test'], $answer->values(), 'Canonical values');
foreach ([false, [], [['host' => 'example.com', 'type' => 'NS', 'class' => 'IN', 'ttl' => 1]],
    [array_merge($rows[0], ['truncated' => true])], [array_merge($rows[0], ['error' => 'SERVFAIL'])],
    [array_merge($rows[0], ['host' => 'unrelated.test'])], [$rows[0], ['type' => 'NS']],
    [array_merge($rows[0], ['ttl' => -1])], [array_merge($rows[0], ['ttl' => '1'])],
    array_fill(0, 101, $rows[0]), [array_merge($rows[0], ['ignored' => str_repeat('x', 65536)])]] as $bad) {
    $rows = $bad; same('unknown', $reader->read($q, new DnsContext())->state, 'Failed/partial is never negative');
}
$throws = new NativeDnsReader(static function (): never { throw new RuntimeException('private resolver diagnostics'); });
same('unknown', $throws->read($q, new DnsContext())->state, 'No exception leak');
$ctx = new DnsContext(function () use (&$time) { return $time; });
$slow = new NativeDnsReader(function () use (&$time, $positiveRows) { $time += 45; return $positiveRows; });
same('unknown', $slow->read($q, $ctx)->state, 'Over-deadline read unknown');
$before = $calls; $reader->read($q, $ctx); same($before, $calls, 'No wire after deadline');
$ctx = new DnsContext(); $fake = new FakeDnsReader([$q->key() => DnsAnswer::negative($q)]);
for ($i = 0; $i < 10; $i++) { same('negative', $fake->read($q, $ctx)->state, 'Explicit complete negative'); }
same('unknown', $fake->read($q, $ctx)->state, 'Ten-query ceiling'); same(10, count($fake->calls), 'No eleventh exchange');
$time = 3.0; $ctx = new DnsContext(function () use (&$time) { return $time; }); $time = 2.0;
expect(!$ctx->reserve(), 'Clock reversal refuses');
foreach (['https://example.com', '127.0.0.1', 'x..com', "x.com\n", 'x.com..', '*.example.com'] as $bad) {
    try { new DnsQuery($bad, 'NS'); expect(false, 'Invalid hostname refused'); } catch (InvalidArgumentException) {}
}
$mx = new DnsQuery('example.com', 'MX');
$null = DnsAnswer::rrset($mx, [['host' => 'example.com', 'type' => 'MX', 'class' => 'IN', 'ttl' => 0, 'target' => '.', 'pri' => 0]]);
same(['.'], $null->values(), 'Null MX preserved');
$nativeNull = new NativeDnsReader(fn() => [['host' => 'example.com', 'type' => 'MX', 'class' => 'IN', 'ttl' => 0, 'target' => '', 'pri' => 0]]);
same(['.'], $nativeNull->read($mx, new DnsContext())->values(), 'Native root MX empty target normalized');
same('unknown', DnsAnswer::rrset($mx, [['host' => 'example.com', 'type' => 'MX', 'class' => 'IN', 'ttl' => 1, 'target' => '.', 'pri' => 1]])->state, 'Invalid null MX unknown');
$a = new DnsQuery('example.com', 'AAAA');
same(['2001:db8::1'], DnsAnswer::rrset($a, [['host' => 'example.com', 'type' => 'AAAA', 'class' => 'IN', 'ttl' => 1, 'ipv6' => '2001:0db8::1']])->values(), 'IP canonicalization');

foreach (['A','AAAA','MX'] as $type) {
    foreach ([[],false] as $empty) {
        $native = new NativeDnsReader(fn() => $empty);
        same('unknown', $native->read(new DnsQuery('example.com',$type),new DnsContext())->state,'Ambiguous native empty/false never proves absence');
    }
}
foreach (['nodata','nxdomain'] as $reason) {
    $negative = DnsAnswer::negative(new DnsQuery('example.com','A'),600,$reason);
    same($reason,$negative->code,'Explicit negative reason retained');same(300,$negative->negativeTtl,'Negative cache diagnostic capped five minutes');
}

$nativeCalls = 0;
$native = new NativeDnsReader(function () use (&$nativeCalls, $positiveRows) { $nativeCalls++; return $positiveRows; });
$ctx = new DnsContext();
for ($i = 0; $i < 10; $i++) { same('rrset', $native->read($q, $ctx)->state, 'Native query within ceiling'); }
same('budget_exhausted', $native->read($q, $ctx)->code, 'Native reader enforces shared query ceiling');
same(10, $nativeCalls, 'Native reader never sends an eleventh exchange');
$time = 0.0; $ctx = new DnsContext(function () use (&$time) { return $time; });
same('rrset', $native->read($q, $ctx)->state, 'Native read before deadline succeeds');
$time = 45.0; same('deadline', $native->read($q, $ctx)->code, 'Native deadline is explicit');
same(11, $nativeCalls, 'Expired native context sends nothing');
foreach ([[-1, 'nodata'], [2147483648, 'nodata'], [0, 'servfail'], [0, '']] as [$ttl, $reason]) {
    same('unknown', DnsAnswer::negative($q, $ttl, $reason)->state, 'Invalid negative TTL or reason refused');
}
$cases = [
    ['NS', [['target' => 'ns.example.com', 'ttl' => -1]]],
    ['NS', [['target' => 'ns.example.com', 'ttl' => 2147483648]]],
    ['NS', [['target' => 'ns.example.com', 'ttl' => '1']]],
    ['CNAME', [['target' => 'one.example.com'], ['target' => 'two.example.com']]],
    ['MX', [['target' => '.', 'pri' => 0], ['target' => 'mx.example.com', 'pri' => 10]]],
    ['A', [['ip' => '2001:db8::1']]],
    ['AAAA', [['ipv6' => '192.0.2.1']]],
];
foreach ($cases as [$type, $rows]) {
    $query = new DnsQuery('example.com', $type);
    $rows = array_map(fn($row) => $row + ['host' => $query->name, 'class' => 'IN', 'type' => $type, 'ttl' => 60], $rows);
    same('unknown', DnsAnswer::rrset($query, $rows)->state, 'Invalid RRset invariant refused: '.$type);
    same('unknown', (new NativeDnsReader(fn() => $rows))->read($query, new DnsContext())->state, 'Native mapping preserves refusal: '.$type);
}
foreach ([new DnsQuery('other.example.com', 'NS'), new DnsQuery('example.com', 'A')] as $wrong) {
    foreach ([DnsAnswer::negative($wrong), fn() => DnsAnswer::negative($wrong)] as $reply) {
        $fake = new FakeDnsReader([$q->key() => $reply]);
        same('unknown', $fake->read($q, new DnsContext())->state, 'Fake reader refuses mismatched answer identity');
        same([$q->key()], $fake->calls, 'Fake records requested key, never returned key');
    }
}
echo "DNS native injected mapping, unknown/negative, validation and shared job bounds passed.\n";
