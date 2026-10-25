<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/reserved-fixtures.php';

foreach (['example.com', 'example.net', 'example.org', 'sealed.example.com', 'child.example.net',
    'child.example.org', 'child-placeholder.example.net', 'child-placeholder.net.test',
    'child-placeholder.org.example.com', 'fixture.test', 'nested.fixture.test'] as $name) {
    same($name, reservedFixtureName($name), 'Reserved fixture identity is preserved');
}
same('child-placeholder.example.net', reservedFixtureName(strtolower('Child-Placeholder.Example.NET')),
    'Mixed-case reserved fixture names normalize');
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

// Scan domain literals in the commerce fixtures that also exercise presentation.
foreach (['invoice-binding-test.php', 'management-privacy-test.php', 'owner-actions-test.php',
    'registration-nameservers-test.php', 'Support/invoice-descriptions.php'] as $fixture) {
    preg_match_all('/(?:[a-z0-9_-]+\.)+(?:com|net|org|de)\b/i', file_get_contents(__DIR__.'/'.$fixture), $names);
    foreach (array_unique($names[0]) as $name) {
        expect(preg_match('/(?:^|\.)example\.(?:com|net|org)$/', $name) === 1
            || str_ends_with($name, '-placeholder.com'), $fixture.' uses a reserved or explicit placeholder name: '.$name);
    }
}

// Captured reply shapes also carry synthetic DNS names in otherwise unused fields.
foreach (['enom-captured-reads.php', 'enom-captured-renewal.php'] as $fixture) {
    $replies = require __DIR__.'/Support/'.$fixture;
    $count = preg_match_all('/<(dns[1-5]?)>([^<]+)<\/\1>/', implode("\n", $replies), $names);
    expect($count > 0, $fixture.' must include nameservers for this policy check');
    foreach ($names[2] as $name) {
        same($name, reservedFixtureName($name), $fixture.' uses reserved nameservers');
    }
}

// Synthetic .net and .org placeholders must use reserved fixture namespaces.
foreach ([__DIR__, __DIR__.'/Support'] as $directory) {
    $files = glob($directory.'/*.php');
    expect(is_array($files) && $files !== [], 'Fixture policy must scan PHP sources');
    foreach ($files as $file) {
        $count = preg_match_all('/\b[a-z0-9.-]*placeholder[a-z0-9.-]*\.[a-z]{2,}\b/i', file_get_contents($file), $names);
        expect($count !== false, 'Fixture placeholder scan must parse');
        foreach (array_unique($names[0]) as $name) {
            $name = strtolower($name);
            if (!str_ends_with($name, '.net') && !str_ends_with($name, '.org')) { continue; }
            same($name, reservedFixtureName($name), basename($file).' uses reserved .net and .org fixture names');
        }
    }
}
// Variable labels in the paired database fixture also use explicit placeholder opt-in.
$deletionFixture=file_get_contents(__DIR__.'/Support/mariadb-user-deletion.php');
expect(preg_match('/\$name\s*=\s*placeholderComFixtureName\(/',$deletionFixture)===1,
    'MariaDB deletion fixture explicitly validates its generated .com placeholder');
foreach(['intent','checkout','snapshot']as$case){
    same($case.'-user-deletion-placeholder.com',placeholderComFixtureName($case.'-user-deletion-placeholder.com'),'Every deletion case uses a valid synthetic placeholder');
}
echo "Explicit synthetic fixture policy passed; no domain availability lookup.\n";
