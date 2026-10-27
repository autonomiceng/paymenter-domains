<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
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
    'register-leap' => Lines::acquisition(reservedFixtureName('leap.example.com'), 'register', 1, $leap),
    'renew-leap' => Lines::renewal(reservedFixtureName('leap.example.com'), $leap, 1),
    'privacy-leap' => Lines::privacy(reservedFixtureName('leap.example.com'), '2028-02-29', $issue),
    'idn' => Lines::renewal('xn--bcher-kva.example.com', $issue, 1),
];
same('Domain registration: example.com (1 year, Oct 19, 2026 to Oct 19, 2027)', $invoiceLineExamples['register'], 'Engine issue day and singular registration wording');
same('Domain registration: example.com (3 years, Oct 19, 2026 to Oct 19, 2029)', $invoiceLineExamples['register-multi'], 'Fixed multi-year registration');
same('Domain transfer: example.com (1 year, added to the current expiry date)', $invoiceLineExamples['transfer'], 'Transfer has no fabricated dates');
same('Domain transfer: example.com (3 years, added to the current expiry date)', Lines::acquisition('example.com', 'transfer', 3, $issue), 'Transfer formatter preserves explicit term; admission still owns eligibility');
same('Domain renewal: example.com (1 year, Oct 19, 2026 to Oct 19, 2027)', $invoiceLineExamples['renew'], 'Locale-independent renewal range');
same('Domain renewal: example.com (3 years, Oct 19, 2026 to Oct 19, 2029)', $invoiceLineExamples['renew-multi'], 'Multi-year renewal range');
same('WHOIS privacy: example.com (1 year, Oct 19, 2026 to Oct 19, 2027)', $invoiceLineExamples['privacy-purchase'], 'Independent privacy purchase year');
same('WHOIS privacy: example.com (1 year, Apr 9, 2027 to Apr 9, 2028)', $invoiceLineExamples['privacy-renew'], 'Privacy expiry, not domain expiry or issue date');
same('Domain registration: leap.example.com (1 year, Feb 29, 2028 to Feb 28, 2029)', $invoiceLineExamples['register-leap'], 'Registration leap-day clamp');
same('Domain renewal: leap.example.com (1 year, Feb 29, 2028 to Feb 28, 2029)', $invoiceLineExamples['renew-leap'], 'Renewal leap-day clamp');
same('Domain renewal: leap.example.com (4 years, Feb 29, 2028 to Feb 29, 2032)', Lines::renewal(reservedFixtureName('leap.example.com'), $leap, 4), 'Multi-year leap anniversary');
same('WHOIS privacy: leap.example.com (1 year, Feb 29, 2028 to Feb 28, 2029)', $invoiceLineExamples['privacy-leap'], 'Privacy leap-day clamp');
same('Domain renewal: bücher.example.com (1 year, Oct 19, 2026 to Oct 19, 2027)', $invoiceLineExamples['idn'], 'Retained IDN displays Unicode only');
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
        'register' => ' (10 years, Oct 19, 2026 to Oct 19, 2036)',
        'transfer' => ' (10 years, added to the current expiry date)',
        'renew' => ' (10 years, Oct 19, 2026 to Oct 19, 2036)',
        'privacy' => ' (1 year, Oct 19, 2026 to Oct 19, 2027)',
    }), 'Every date and qualifier survives truncation '.$kind);
    $invoiceLineExamples[$kind.'-long'] = $short;
}
// Invoice views show the text before the final parentheses as the title and the period under it.
foreach ($invoiceLineExamples as $key => $line) {
    expect(preg_match('/^(Domain registration|Domain transfer|Domain renewal|WHOIS privacy): [^()]+ \(\d+ years?, [^()]+\)$/u', $line) === 1, 'Title and period split cleanly: '.$key);
}
$unicodeLong = str_repeat('é', 250).'.test';
$unicodeLine = Lines::renewal($unicodeLong, $issue, 1);
same(255, mb_strlen($unicodeLine, 'UTF-8'), 'Unicode character rather than byte bound');
expect(mb_check_encoding($unicodeLine, 'UTF-8'), 'Truncation preserves valid UTF-8');
unset($issue, $leap, $longName, $unicodeLong, $unicodeLine, $short, $kind, $key, $line);
