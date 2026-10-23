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
foreach (['transfer-placeholder.com', 'late-page-placeholder-2.com'] as $name) {
    same($name, placeholderComFixtureName($name), 'Single-label .com placeholder is preserved');
}
foreach (['ordinary.com', 'example.com', 'two.labels-placeholder.com', 'not-placeholder.test'] as $name) {
    $rejected = false;
    try { placeholderComFixtureName($name); } catch (InvalidArgumentException) { $rejected = true; }
    expect($rejected, 'Placeholder .com fixture policy must reject '.$name);
}
echo "Explicit synthetic fixture policy passed; no domain availability lookup.\n";

// Scan domain literals in the commerce fixtures that also exercise presentation.
foreach (['invoice-binding-test.php', 'management-privacy-test.php', 'owner-actions-test.php',
    'registration-nameservers-test.php', 'Support/invoice-descriptions.php'] as $fixture) {
    preg_match_all('/(?:[a-z0-9_-]+\.)+(?:com|net|org|de)\b/i', file_get_contents(__DIR__.'/'.$fixture), $names);
    foreach (array_unique($names[0]) as $name) {
        expect(preg_match('/(?:^|\.)example\.(?:com|net|org)$/', $name) === 1
            || str_ends_with($name, '-placeholder.com'), $fixture.' uses a reserved or explicit placeholder name: '.$name);
    }
}
