<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/Support/fixture-random.php';

// The seeded stream repeats per seed and keeps the Domains and framework streams apart, so case digests repeat and a
// framework draw cannot shift a Domains key.
$draw = static function (string $seed, string $stream, int $count = 2): array {
    NativeFixtureRandom::reseed($seed);
    $out = [];
    for ($i = 0; $i < $count; $i++) { $out[] = bin2hex(NativeFixtureRandom::bytes(16, $stream)); }
    return $out;
};
$domains = $draw('case-a', 'domains');
same($domains, $draw('case-a', 'domains'), 'The same seed draws the same Domains bytes');
expect($domains[0] !== $domains[1], 'Successive draws differ');
expect($draw('case-b', 'domains') !== $domains, 'Another seed draws other bytes');
expect($draw('case-a', 'framework') !== $domains, 'The framework stream differs from the Domains stream');

NativeFixtureRandom::reseed('case-a');
NativeFixtureRandom::bytes(32, 'framework');
same($domains[0], bin2hex(NativeFixtureRandom::bytes(16, 'domains')), 'A framework draw does not shift the Domains stream');
same(40, strlen(NativeFixtureRandom::bytes(40, 'domains')), 'A draw longer than one block keeps its length');

NativeFixtureRandom::reseed('case-a');
NativeFixtureRandom::bytes(16, 'domains');
$position = NativeFixtureRandom::position();
$next = bin2hex(NativeFixtureRandom::bytes(16, 'domains'));
NativeFixtureRandom::reseed('other');
NativeFixtureRandom::resumeSnapshot("-- random-stream: ".json_encode($position)."\nCREATE TABLE t (id int);");
same($next, bin2hex(NativeFixtureRandom::bytes(16, 'domains')), 'A loaded snapshot continues from its exported position');
same('Paymenter\\Extensions\\Servers\\Domains\\Services\\random_bytes', (new ReflectionFunction('Paymenter\\Extensions\\Servers\\Domains\\Services\\random_bytes'))->getName(),
    'The Services shim is defined');
echo "fixture random stream passed\n";
