<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Services\InvoiceDescription as Lines;

$issue = new DateTimeImmutable('2026-10-19T00:30:00+02:00');
$leap = new DateTimeImmutable('2028-02-29T12:00:00Z');
$invoiceLineExamples = [
    'register' => Lines::acquisition('example.com', 'register', 1, $issue),
    'register-multi' => Lines::acquisition('example.com', 'register', 3, $issue),
    'transfer' => Lines::acquisition('example.com', 'transfer', 1, $issue),
    'renew' => Lines::renewal('example.com', $issue, 1),
    'renew-multi' => Lines::renewal('example.com', $issue, 3),
    'privacy-purchase' => Lines::privacy('example.com', null, $issue),
    'privacy-renew' => Lines::privacy('example.com', '2027-04-09', $issue),
    'register-leap' => Lines::acquisition('leap-placeholder.com', 'register', 1, $leap),
    'renew-leap' => Lines::renewal('leap-placeholder.com', $leap, 1),
    'privacy-leap' => Lines::privacy('leap-placeholder.com', '2028-02-29', $issue),
    'idn' => Lines::renewal('xn--bcher-kva.de', $issue, 1),
];
same('Register example.com: 1 year (Oct 19, 2026 - Oct 19, 2027, from registration)', $invoiceLineExamples['register'], 'Engine issue day and singular registration wording');
same('Register example.com: 3 years (Oct 19, 2026 - Oct 19, 2029, from registration)', $invoiceLineExamples['register-multi'], 'Fixed multi-year registration');
same('Transfer example.com: adds 1 year to the current expiry', $invoiceLineExamples['transfer'], 'Transfer has no fabricated dates');
same('Transfer example.com: adds 3 years to the current expiry', Lines::acquisition('example.com', 'transfer', 3, $issue), 'Transfer formatter preserves explicit term; admission still owns eligibility');
same('Renew example.com: Oct 19, 2026 - Oct 19, 2027', $invoiceLineExamples['renew'], 'Locale-independent renewal range');
same('Renew example.com: Oct 19, 2026 - Oct 19, 2029', $invoiceLineExamples['renew-multi'], 'Multi-year renewal range');
same('Domain privacy example.com: Oct 19, 2026 - Oct 19, 2027 (from purchase)', $invoiceLineExamples['privacy-purchase'], 'Independent privacy purchase year');
same('Domain privacy example.com: Apr 09, 2027 - Apr 09, 2028', $invoiceLineExamples['privacy-renew'], 'Privacy expiry, not domain expiry or issue date');
same('Register leap-placeholder.com: 1 year (Feb 29, 2028 - Feb 28, 2029, from registration)', $invoiceLineExamples['register-leap'], 'Registration leap-day clamp');
same('Renew leap-placeholder.com: Feb 29, 2028 - Feb 28, 2029', $invoiceLineExamples['renew-leap'], 'Renewal leap-day clamp');
same('Renew leap-placeholder.com: Feb 29, 2028 - Feb 29, 2032', Lines::renewal('leap-placeholder.com', $leap, 4), 'Multi-year leap anniversary');
same('Domain privacy leap-placeholder.com: Feb 29, 2028 - Feb 28, 2029', $invoiceLineExamples['privacy-leap'], 'Privacy leap-day clamp');
same('Renew bücher.de: Oct 19, 2026 - Oct 19, 2027', $invoiceLineExamples['idn'], 'Retained IDN displays Unicode only');
$longName = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61);
same(253, strlen($longName), 'Maximum canonical DNS name fixture');
foreach (['register', 'transfer', 'renew', 'privacy'] as $kind) {
    $short = match ($kind) {
        'register', 'transfer' => Lines::acquisition($longName, $kind, 10, $issue),
        'renew' => Lines::renewal($longName, $issue, 10),
        'privacy' => Lines::privacy($longName, null, $issue),
    };
    same(255, mb_strlen($short, 'UTF-8'), 'Native description bound '.$kind);
    expect(str_contains($short, '…') && str_contains($short, str_repeat('d', 20)), 'Middle truncation preserves name ending '.$kind);
    expect(str_ends_with($short, match ($kind) {
        'register' => ': 10 years (Oct 19, 2026 - Oct 19, 2036, from registration)',
        'transfer' => ': adds 10 years to the current expiry',
        'renew' => ': Oct 19, 2026 - Oct 19, 2036',
        'privacy' => ': Oct 19, 2026 - Oct 19, 2027 (from purchase)',
    }), 'Every date and qualifier survives truncation '.$kind);
    $invoiceLineExamples[$kind.'-long'] = $short;
}
$unicodeLong = str_repeat('é', 250).'.de';
$unicodeLine = Lines::renewal($unicodeLong, $issue, 1);
same(255, mb_strlen($unicodeLine, 'UTF-8'), 'Unicode character rather than byte bound');
expect(mb_check_encoding($unicodeLine, 'UTF-8'), 'Truncation preserves valid UTF-8');
unset($issue, $leap, $longName, $unicodeLong, $unicodeLine, $short, $kind);
