<?php
// Synthetic C0 registry facts; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Ds, DsRegistryPolicy};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DsMapping, RegistrarRequest};
expect(is_file(dirname(__DIR__, 2).'/Domains/Registrars/NameSilo/DsRegistryPolicy.php'), 'Versioned DS policy exists');
$now = new DateTimeImmutable('2026-11-04T12:00:00+00:00');
$row = ['key_tag'=>12345, 'algorithm'=>13, 'digest_type'=>2, 'digest'=>str_repeat('AB', 32)];
$fact = ['suffix'=>'example', 'input'=>'ds', 'algorithms'=>[13, 14], 'digest_types'=>[2, 4],
    'combinations'=>[['algorithm'=>13, 'digest_type'=>2], ['algorithm'=>14, 'digest_type'=>4]],
    'source'=>'synthetic-registry', 'version'=>'ds-rule-1', 'reviewed_at'=>'2026-11-01T00:00:00+00:00',
    'valid_until'=>'2026-12-01T00:00:00+00:00', 'expiry_policy'=>'refuse'];
$map = ['format'=>1, 'entries'=>['example'=>$fact]];
$policy = new DsRegistryPolicy($map, $now);
same(false, (new DsRegistryPolicy)->allows('example.com', $row), 'Default deployment map stays empty');
same(false, (new DsRegistryPolicy($map))->allows('child.example', $row), 'No ambient policy clock');
same(true, $policy->allows('CHILD.EXAMPLE.', $row), 'Current reviewed explicit pair accepted');
same(['format'=>1, ...$fact], $policy->snapshot('child.example'), 'Complete policy and version freeze for new requests');
$cross = array_replace($row, ['digest_type'=>4, 'digest'=>str_repeat('AB', 48)]);
same(false, $policy->allows('child.example', $cross), 'Independent allowed sets do not imply their cross product');
foreach ([null, array_replace($fact, ['suffix'=>'key.example', 'input'=>'key']),
    array_replace($fact, ['suffix'=>'key.example', 'valid_until'=>$now->format(DATE_ATOM)]),
    array_replace($fact, ['suffix'=>'key.example'])] as $longer) {
    $p = new DsRegistryPolicy(['format'=>1, 'entries'=>['example'=>$fact, 'key.example'=>$longer]], $now);
    same($longer !== null && $longer['input']==='ds' && $longer['valid_until']!==$now->format(DATE_ATOM),
        $p->allows('child.key.example', $row), 'Longest raw suffix blocks broader fallback even when facts are invalid');
    foreach (['key.example', 'KEY.EXAMPLE.', 'sub.child.key.example'] as $name) {
        same(false, $p->allows($name, $row), 'Suffix itself and deeper subdomains refuse');
    }
}
foreach (['example.com', 'example', 'sub.child.example', 'bad/child.example'] as $name) {
    same(false, $policy->allows($name, $row), 'No unknown ending or extra-label grant');
}
foreach (['input'=>'key', 'source'=>'', 'version'=>'', 'reviewed_at'=>'2026-02-30T00:00:00+00:00',
    'valid_until'=>$now->format(DATE_ATOM), 'expiry_policy'=>'ignore', 'algorithms'=>['13'], 'combinations'=>[]] as $key=>$bad) {
    $copy=$map; $copy['entries']['example'][$key]=$bad;
    same(false, (new DsRegistryPolicy($copy, $now))->allows('child.example', $row), 'Malformed or stale policy refuses '.$key);
}
foreach ([[], ['example'=>$fact], ['format'=>2, 'entries'=>['example'=>$fact]]] as $bad) {
    same(false, (new DsRegistryPolicy($bad, $now))->allows('child.example', $row), 'Legacy or unknown map version is closed');
}
foreach (['add'=>'dnsSecAddRecord', 'delete'=>'dnsSecDeleteRecord'] as $method=>$command) {
    $m=DsMapping::$method('CHILD.EXAMPLE.', $row, $policy);
    same($command, $m->command, 'Exact named tuple mutation');
    same(['domain'=>'child.example', 'digest'=>$row['digest'], 'keyTag'=>12345, 'digestType'=>2, 'alg'=>13],
        $m->parameters(), 'Full tuple, no invented provider ID');
    expect(!$m instanceof RegistrarRequest, 'Offline descriptor cannot enter the read sender');
    foreach ([json_encode($m), serialize($m), print_r($m, true), (string)$m] as $visible) {
        expect(!str_contains($visible, $row['digest']) && !str_contains($visible, 'child.example'), 'Descriptor diagnostics hide tuple and domain');
    }
    try { unserialize(serialize($m)); throw new RuntimeException('Restored descriptor'); } catch (LogicException) {}
}
echo "Versioned DS policy passed\n";
// Enabling a DS ending must not activate the separate dormant DNS editor.
$clock = new class($now) implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public function __construct(private DateTimeImmutable $at) {}
    public function now(): DateTimeImmutable { return $this->at; }
};
$b=new \Paymenter\Extensions\Servers\Domains\Data\AccountBinding('namesilo',1,\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,'synthetic');
$driver=new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DsManagement($b,static fn()=>throw new RuntimeException('No wire'),$map,$clock);
same(\Paymenter\Extensions\Servers\Domains\Data\Support::Unsupported,$driver->capabilities()->get('dns')->support,'DS facts never activate unrelated DNS editing');
