<?php
// Registrar requirement words name only what the registrar said; an unknown flag never reads as a stated requirement.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Facts, Writer};

$seen = new DateTimeImmutable('2026-11-10T20:00:00Z');
$facts = static fn (?bool $renewalSafe, ?bool $nameservers, ?bool $autoRenew): Facts =>
    new Facts(['register' => [1], 'renew' => [1]], [], $renewalSafe, $nameservers, $autoRenew, 'synthetic_terms', $seen, $seen->modify('+1 day'));
$cases = [
    'nameservers required' => [[true, true, false], TldFacts::UNSUPPORTED[1]],
    'renews only automatically' => [[true, false, true], TldFacts::UNSUPPORTED[2]],
    'renewals refused' => [[false, false, false], TldFacts::UNSUPPORTED[3]],
    // eNom without dnsrequired: the registrar did not say it needs nameservers.
    'nameserver flag missing' => [[true, null, false], TldFacts::UNSUPPORTED[4]],
    // A missing autorenewonly leaves both auto-renew and renewal safety unknown: renewals are not confirmed.
    'auto-renew flag missing' => [[null, false, null], TldFacts::UNSUPPORTED[3]],
];
$actual = [];
foreach ($cases as $name => [$flags, $words]) { $actual[$name] = Writer::unsupportedCause($facts(...$flags), 'enom', 'com'); }
same(array_map(static fn (array $case): string => $case[1], $cases), $actual, 'Requirement words follow what the registrar said: '.json_encode($actual));
$nativeSuccessful = true; echo "Registrar requirement words passed.\n";
