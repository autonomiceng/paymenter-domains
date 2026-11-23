<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real Blade customer presentation cases.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Dns\CompanyLabels;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary;
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaryStrings;
(function (): void {
    require dirname(__DIR__, 2).'/Domains/routes/owned.php';
    storefrontViews();
    $all = 'This page uses a test system. Nothing here is registered for real.';
    $some = "Items marked Test use a test system and aren't registered for real.";
    $data = storefrontData();
    $render = static fn ($rows, $test) => view('domains::pricing', ['rows' => $rows, 'guest' => true, 'testEnvironment' => $test])->render();
    foreach ([true, 'some', false] as $test) {
        $rows = $data['endings'];
        foreach ($rows as $i => &$row) { $row['terms'] = [1]; $row['testEnvironment'] = $test === true || ($test === 'some' && $i === 0); } unset($row);
        $html = $render($rows, $test); $dom = storefrontDom($html);
        same($test ? 1 : 0, $dom->query('//*[@class="domains-testbar"]')->length, 'D7 pricing banner presence: '.json_encode($test));
        if ($test) { expect(str_contains($html, $test === true ? $all : $some), 'D7 exact pricing banner: '.json_encode($test)); }
        // Every test row keeps its Test tag, on mixed and all-test pages alike.
        same($test === true ? 3 : ($test === 'some' ? 1 : 0), $dom->query('//tbody/tr/th/span[contains(concat(" ",@class," ")," domains-demo-tag--test ")]')->length, 'D7 pricing test tags match rows');
        $transfer = storefrontDom(view('domains::transfer', [...$data, 'endings' => $rows, 'results' => [], 'testEnvironment' => $test])->render());
        same($test === true ? 3 : ($test === 'some' ? 1 : 0), $transfer->query('//tbody/tr/th/span[contains(concat(" ",@class," ")," domains-demo-tag--test ")]')->length, 'D7 transfer price test tags match rows');
        same(4, $dom->query('//thead/tr/th')->length, 'D7 annual pricing has four columns');
        same(0, $dom->query('//td[@data-label="Register for"]')->length, 'D7 annual term cells hidden');
        same(3, $dom->query('//td[@data-label="Renew"]')->length, 'D7 mobile renew labels match the column');
        same(['Every TLD we sell, with its register, renew and transfer price per year.'], array_map(fn($n) => $n->textContent, iterator_to_array($dom->query('//p[@class="domains-lede"]'))), 'D7 lede states the annual unit once');
        same(1, substr_count(strip_tags($html), 'per year'), 'D7 annual unit is said once');
        expect(!str_contains($html, '/yr'), 'D7 pricing never duplicates the annual suffix');
        // These rows register for less than they renew, so the footnote says why; equal prices are in storefront-first-year-note-test.
        $differs = collect($rows)->contains(fn ($r) => ($r['register'] ?? null) !== null && ($r['renew'] ?? null) !== null && $r['register'] !== $r['renew']);
        same([true, 1], [$differs, substr_count($html, 'Prices plus tax where applicable. '.\Paymenter\Extensions\Servers\Domains\Presentation\FirstYearPrice::LIST.'</p>')],
            'D7 annual footnote: tax, and why Register and Renew differ');
        expect(!str_contains($html, 'Longer terms are priced'), 'D7 annual pricing has no longer-term footnote');
    }
    $rows = $data['endings']; $rows[0]['terms'] = [2]; $rows[1]['terms'] = []; $rows[1]['renew'] = null;
    $html = $render($rows, false); $dom = storefrontDom($html);
    same(5, $dom->query('//thead/tr/th')->length, 'D7 longer or absent registration terms retain column');
    same(3, $dom->query('//td[@data-label="Register for"]')->length, 'D7 conditional column retains every row cell');
    same(\Paymenter\Extensions\Servers\Domains\Presentation\State::NotSet->label().' '.\Paymenter\Extensions\Servers\Domains\Presentation\Missing::ASK_FIRST, preg_replace('/\s+/', ' ', trim($dom->query('//td[@data-label="Renew"]')->item(1)->textContent)), 'D7 missing renewal price says what to do next');
    $rows[1]['transfer'] = null; $rows[1]['transfersOn'] = true; $rows[2]['transfer'] = null; $rows[2]['transfersOn'] = false;
    $cells = storefrontDom($render($rows, false))->query('//td[@data-label="Transfer"]');
    same([\Paymenter\Extensions\Servers\Domains\Presentation\State::NotSet->label().' '.\Paymenter\Extensions\Servers\Domains\Presentation\Missing::ASK_FIRST, \Paymenter\Extensions\Servers\Domains\Presentation\State::NotForSale->label().' We don’t take transfers for this TLD.'], array_map(fn($i) => preg_replace('/\s+/', ' ', trim($cells->item($i)->textContent)), [1, 2]), 'D7 a transfer with no price says what to do next; off stays off');
    expect(str_contains($html, 'Longer terms are priced per term; search for a name to see each total.'), 'D7 longer single term qualifies for footnote');
    $rows[0]['terms'] = [1];
    expect(!str_contains($render($rows, false), 'Longer terms are priced'), 'D7 absent registration terms do not invent longer terms');
    // The index describes the visible page, including filtered/empty pages, not hidden accounts.
    $strings0 = DomainSummaryStrings::all();
    foreach ([[true, true], [true, false], [false, false], []] as $flags) {
        $dtos = [];
        foreach ($flags as $i => $flag) {
            $dtos[] = new \Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary(reservedFixtureName('example-'.$i.'.example.com'), null, 'managed',
                'domain.registration.platform', ['domain.active'],
                \Paymenter\Extensions\Servers\Domains\Services\DomainSummaryStrings::all(), null, null, null, null, null,
                ['detail' => '/domains/'.$i], testEnvironment: $flag);
        }
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(collect($dtos), count($dtos), 25);
        $html = view('domains::index', ['domains' => $paginator])->render(); $dom = storefrontDom($html);
        $tests = count(array_filter($flags));
        same(0, $dom->query('//*[@class="domains-testbar"]')->length, 'Owned list has no test banner');
        same($tests, $dom->query('//tbody/tr/td/span[contains(@class,"domains-demo-tag--test")]')->length, 'Owned list tags each test registration');
    }
    $emptyFiltered = view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator([], 50, 25)])->render();
    same(0, storefrontDom($emptyFiltered)->query('//*[@class="domains-testbar"]')->length, 'D7 empty visible page ignores hidden totals');
    // One status pill per domain. Registration, DNS, renewal and check state are plain text in their own sections.
    $strings = DomainSummaryStrings::all();
    $dto = static fn (string $name, string $kind, string $registration, array $states, ?string $state = null) => new DomainSummary($name, null, $kind,
        $registration, $states, $strings, null, null, null, null, null, ['detail' => '/domains/'.$name], renewalOff: in_array('adopt.renewal_off', $states, true),
        statusState: $state);
    $rows = [
        'active-unchecked' => ['domain.registration.platform', ['domain.active'], 'Active', 'active'],
        'renewal-off' => ['domain.registration.platform', ['domain.active', 'adopt.renewal_off'], 'Active', 'active'],
        'held' => ['domain.registration.unknown', ['domain.review'], 'Under review', 'needs_review'],
        'known' => ['domain.registration.platform', ['domain.active', 'adopt.provenance'], 'Active', 'active'],
    ];
    $summaries = array_map(fn ($name) => $dto($name.'.example', 'managed', ...[...array_slice($rows[$name], 0, 2), $rows[$name][3]]), array_keys($rows));
    $html = view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator($summaries,count($summaries),25)])->render();
    $dom = storefrontDom($html);
    $pills = static fn ($dom, $context = null) => array_map(fn ($span) => trim($span->textContent), iterator_to_array($dom->query('.//span[contains(@class,"domains-badge")] | .//td[@data-label="Status"]//*[@data-domains-tone]', $context ?? $dom->document->documentElement)));
    foreach (array_keys($rows) as $i => $name) {
        same([$rows[$name][2]], $pills($dom, $dom->query('//tbody/tr['.($i + 1).']')->item(0)), 'List row has one status pill: '.$name);
    }
    same('Status', trim($dom->query('//thead/tr/th[2]')->item(0)->textContent), 'List column is Status');
    $detail = view('domains::partials.summary', ['summary' => $summaries[2]])->render();
    $nameservers = static fn (DOMXPath $dom) => preg_replace('/\s+/', ' ', trim($dom->query('//div[@class="domains-fact"][dt="Nameservers"]/dd')->item(0)?->textContent ?? ''));
    same('Missing Fills in by itself after the first check', $nameservers(storefrontDom($detail)), 'Unchecked detail says Nameservers Missing with its cause');
    same(0, storefrontDom($detail)->query('//details')->length, 'Unchecked detail has no disclosure');
    // Notices stay notices; adoption and renewal state never become pills.
    $compact = static fn (DomainSummary $summary, array $with = []) => storefrontDom(view('domains::partials.summary', ['summary' => $summary, ...$with])->render());
    $adopting = $dto('adopting.example', 'managed', 'domain.registration.platform', ['adopt.pending', 'adopt.provenance', 'domain.active'], 'adoption_pending');
    same(['Under review'], $pills($compact($adopting)), 'Pending outranks active');
    same([], $pills($compact($adopting, ['summaryOnManaged' => true])), 'Managed context leaves the pill to the page header');
    $offRow = $dom->query('//tbody/tr[td/a[normalize-space()="renewal-off.example"]]')->item(0);
    same('Off', trim($dom->query('td[@data-label="Auto-renew"]', $offRow)->item(0)->textContent), 'Renewal column says Off');
    same(0, substr_count($offRow->textContent, 'Auto-renew off'), 'Index row leaves Off to the Renewal column');
    $pendingOff = new DomainSummary('pending-off.example', null, 'managed', 'domain.registration.unknown', ['adopt.pending', 'adopt.renewal_off'], $strings, null, null, null, null, null, ['detail' => '/domains/pending-off.example'], renewalOff: true);
    $pendingRow = storefrontDom(view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator([$pendingOff], 1, 25)])->render())->query('//tbody/tr')->item(0);
    expect(!$pendingOff->renewalOffShown(), 'Pending verification never counts as a shown renewal off');
    same('On', trim($pendingRow->getElementsByTagName('td')->item(3)->textContent), 'Pending row Renewal column: the Under review pill explains the wait');
    same(0, substr_count($pendingRow->textContent, 'Auto-renew off'), 'Pending row never says Auto-renew off');
    foreach ([true, 'some', false] as $test) {
        $search = storefrontData(); $search['testEnvironment'] = $test;
        foreach ($search['results'] as $i => &$row) { $row['testEnvironment'] = $test === true || ($test === 'some' && $i === 0); } unset($row);
        $html = view('domains::search', $search)->render(); $dom = storefrontDom($html);
        same($test ? 1 : 0, $dom->query('//*[@class="domains-testbar"]')->length, 'D7 search all/some/none banner');
        if ($test) { expect(str_contains($html, $test === true ? $all : $some), 'D7 search uses shared exact copy'); }
        same($test === true ? 7 : ($test === 'some' ? 1 : 0), $dom->query('//li[@data-domains-result]/div[1]//span[normalize-space()="Test"]')->length, 'D7 search tags only test results');
    }
    $html = view('domains::partials.result', ['result' => [...$data['results'][0], 'testEnvironment' => true]])->render();
    same(1, storefrontDom($html)->query('//div[1]//span[normalize-space()="Test"]')->length, 'D7 standalone AJAX partial retains test label');
})();
