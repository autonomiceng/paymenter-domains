<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/reserved-fixtures.php';

foreach (['example.com', 'example.net', 'example.org', 'sealed.example.com', 'child.example.net',
    'child.example.org', 'fixture.test', 'nested.fixture.test'] as $name) {
    same($name, reservedFixtureName($name), 'Reserved fixture identity is preserved');
}
foreach (['rate-queued.com', 'other.com', 'example-sealed.com', 'timestamp-boundary.com',
    'example.com.evil.com', 'notexample.com', 'invalid..example.com'] as $name) {
    $rejected = false;
    try { reservedFixtureName($name); } catch (InvalidArgumentException) { $rejected = true; }
    expect($rejected, 'Fixture policy must reject '.$name);
}
echo "Explicit synthetic fixture policy passed; no domain availability lookup.\n";
