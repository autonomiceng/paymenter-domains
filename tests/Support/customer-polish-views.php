<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real Blade customer presentation cases.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
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
        same($test === true ? 3 : ($test === 'some' ? 1 : 0), $dom->query('//tbody/tr/th/span[normalize-space()="Test"]')->length, 'D7 pricing test tags match rows');
        same(4, $dom->query('//thead/tr/th')->length, 'D7 annual pricing has four columns');
        same(0, $dom->query('//td[@data-label="Register for"]')->length, 'D7 annual term cells hidden');
        same(3, $dom->query('//td[@data-label="Renew"]')->length, 'D7 mobile renew labels match the column');
        same(['Every TLD we sell, with its register, renew and transfer price per year.'], array_map(fn($n) => $n->textContent, iterator_to_array($dom->query('//p[@class="domains-lede"]'))), 'D7 lede states the annual unit once');
        same(1, substr_count(strip_tags($html), 'per year'), 'D7 annual unit is said once');
        expect(!str_contains($html, '/yr'), 'D7 pricing never duplicates the annual suffix');
        expect(str_contains($html, 'Prices plus tax where applicable.</p>'), 'D7 annual footnote is one sentence');
        expect(!str_contains($html, 'Longer terms are priced'), 'D7 annual pricing has no longer-term footnote');
    }
    $rows = $data['endings']; $rows[0]['terms'] = [2]; $rows[1]['terms'] = []; $rows[1]['renew'] = null;
    $html = $render($rows, false); $dom = storefrontDom($html);
    same(5, $dom->query('//thead/tr/th')->length, 'D7 longer or absent registration terms retain column');
    same(3, $dom->query('//td[@data-label="Register for"]')->length, 'D7 conditional column retains every row cell');
    same('Not offered', trim($dom->query('//td[@data-label="Renew"]')->item(1)->textContent), 'D7 missing renewal price says Not offered');
    expect(str_contains($html, 'Longer terms are priced per term; search for a name to see each total.'), 'D7 longer single term qualifies for footnote');
    $rows[0]['terms'] = [1];
    expect(!str_contains($render($rows, false), 'Longer terms are priced'), 'D7 absent registration terms do not invent longer terms');
    // The index describes the visible page, including filtered/empty pages, not hidden accounts.
    $strings0 = DomainSummaryStrings::all();
    foreach ([[true, true], [true, false], [false, false], []] as $flags) {
        $dtos = [];
        foreach ($flags as $i => $flag) {
            $dtos[] = new \Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary(reservedFixtureName('example-'.$i.'.example.com'), null, 'managed',
                'domain.registration.platform', ['domain.dns.unknown'], null, null, ['domain.active'],
                \Paymenter\Extensions\Servers\Domains\Services\DomainSummaryStrings::all(), null, null, null, [], null, null, [], [],
                ['detail' => '/domains/'.$i], testEnvironment: $flag);
        }
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(collect($dtos), count($dtos), 25);
        $html = view('domains::index', ['domains' => $paginator])->render(); $dom = storefrontDom($html);
        $tests = count(array_filter($flags));
        same(0, $dom->query('//*[@class="domains-testbar"]')->length, 'Owned list has no test banner');
        same($tests, $dom->query('//tbody/tr/td/span[contains(@class,"domains-demo-tag--test")]')->length, 'Owned list tags each test registration');
        same(0, substr_count($html, $strings0['domain.registration.platform']), 'Owned list has no registration badge');
    }
    $emptyFiltered = view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator([], 50, 25)])->render();
    same(0, storefrontDom($emptyFiltered)->query('//*[@class="domains-testbar"]')->length, 'D7 empty visible page ignores hidden totals');
    // One status pill per domain. Registration, DNS, renewal and check state are plain text in their own sections.
    $strings = DomainSummaryStrings::all();
    // Managed rows carry the resolved state; a monitored row resolves from its findings, as DomainSummaries does.
    $dto = static fn (string $name, string $kind, string $registration, array $dnsKeys, array $states, ?string $state = null) => new DomainSummary($name, null, $kind,
        $registration, $dnsKeys, null, null, $states, $strings, null, null, null, [], null, null, [], [], ['detail' => '/domains/'.$name], renewalOff: in_array('adopt.renewal_off', $states, true),
        statusState: $kind === 'watch' ? (\Paymenter\Extensions\Servers\Domains\Services\DomainStates::watch($states, null, false)[0] ?? null) : $state);
    $unknown = DnsSignalLabels::keys(null, null, null);
    $rows = [
        'active-unchecked' => ['domain.registration.platform', $unknown, ['domain.active', 'watch.not_checked'], 'Active', 'active'],
        'renewal-off' => ['domain.registration.platform', $unknown, ['domain.active', 'adopt.renewal_off', 'watch.not_checked'], 'Active', 'active'],
        'held' => ['domain.registration.unknown', $unknown, ['domain.review', 'watch.not_checked'], 'Under review', 'needs_review'],
        'stale' => ['domain.registration.platform', DnsSignalLabels::keys(null, null, null, partial: true), ['domain.active', 'watch.stale', 'watch.partial'], 'Active', 'active'],
        'known' => ['domain.registration.platform', DnsSignalLabels::keys('platform', false, false), ['domain.active', 'adopt.provenance'], 'Active', 'active'],
        // A managed domain's nameserver change is a notice on its page, not its status.
        'moved' => ['domain.registration.platform', DnsSignalLabels::keys('external', false, false), ['domain.active', 'watch.ns_changed'], 'Active', 'active'],
    ];
    $summaries = array_map(fn ($name) => $dto($name.'.example', 'managed', ...[...array_slice($rows[$name], 0, 3), $rows[$name][4]]), array_keys($rows));
    $watches = [$dto('watch-unchecked.example', 'watch', 'domain.registration.unknown', $unknown, ['watch.not_checked']), $dto('watch-known.example', 'watch', 'domain.registration.external', DnsSignalLabels::keys('external', false, false), []), $dto('related-off.example', 'managed', 'domain.registration.platform', DnsSignalLabels::keys('platform', false, false), ['domain.active', 'adopt.renewal_off'], 'active')];
    // One list: hosting domains are rows after the registered domains on this page.
    $html = view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator([...$summaries, ...$watches], count($summaries) + count($watches), 25)])->render();
    $dom = storefrontDom($html);
    $pills = static fn ($dom, $context = null) => array_map(fn ($span) => trim($span->textContent), iterator_to_array($dom->query('.//span[contains(@class,"domains-badge")]', $context ?? $dom->document->documentElement)));
    foreach (array_keys($rows) as $i => $name) {
        same([$rows[$name][3]], $pills($dom, $dom->query('//tbody/tr['.($i + 1).']')->item(0)), 'List row has one status pill: '.$name);
    }
    same('Status', trim($dom->query('//thead/tr/th[2]')->item(0)->textContent), 'List column is Status');
    $evidence = [...array_map(fn ($key) => $strings[$key], [...array_keys(DnsSignalLabels::strings('Example Hosting')), 'domain.registration.platform', 'domain.registration.external', 'domain.registration.unknown', 'domain.details.partial', 'watch.not_checked', 'watch.stale', 'watch.partial']), 'Adopted registration', 'Renewal off', 'Auto-renew off', 'At your registrar'];
    same([], array_intersect($pills($dom), $evidence), 'No registration, DNS, renewal or check pills on the list');
    $related = array_map(fn ($i) => $dom->query('//tbody/tr['.$i.']')->item(0), range(count($summaries) + 1, count($summaries) + count($watches)));
    same([[], [], ['Active']], array_map(fn ($node) => $pills($dom, $node), $related), 'Hosting rows: no pill without current registry facts; managed row shows Active');
    same(['At your registrar', 'At your registrar', 'Auto-renew off'], array_map(fn ($node) => trim($dom->query('td[@data-label="Renewal"]', $node)->item(0)->textContent), $related), 'Hosting rows renew at their registrar; a registered row keeps its own renewal');
    $detail = view('domains::partials.summary', ['summary' => $summaries[2]])->render();
    foreach (['domain.registration.unknown', 'domain.dns.unknown', 'domain.points.unknown', 'watch.not_checked'] as $key) { expect(!str_contains($detail, '>'.$strings[$key].'<'), 'Detail shows no chip that only says something is unknown: '.$key); }
    $nameservers = static fn (DOMXPath $dom) => preg_replace('/\s+/', ' ', trim($dom->query('//div[@class="domains-fact"][dt="Nameservers"]/dd')->item(0)?->textContent ?? ''));
    same('Missing Shows after the first check', $nameservers(storefrontDom($detail)), 'Unchecked detail says Nameservers Missing with its cause');
    same(0, storefrontDom($detail)->query('//details')->length, 'Unchecked detail has no disclosure');
    $fresh = ['delegation' => ['checkedAt' => null, 'stale' => false, 'partial' => false, 'nextCheck' => 1767225600], 'points' => ['checkedAt' => 1767139200, 'stale' => true, 'partial' => true, 'nextCheck' => null]];
    $detailDom = storefrontDom(view('domains::partials.summary', ['summary' => new DomainSummary('held.example', null, 'managed', 'domain.registration.unknown', $unknown, null, null, ['domain.review', 'watch.not_checked'], $strings, null, null, null, $fresh, null, null, [], [], [])])->render());
    same(0, $detailDom->query('//details')->length, 'Detail has no check disclosure');
    same('Missing Shows after the first check', $nameservers($detailDom), 'A pointer check never stands in for a nameserver check');
    foreach (['Out of date', 'Last checked', 'Next check', 'pointer'] as $old) { expect(!str_contains($detailDom->document->saveHTML(), $old), 'Detail drops check bookkeeping: '.$old); }
    // DNS evidence moved from chips into the check details as plain values.
    $checked = ['delegation' => ['checkedAt' => 1767139200, 'stale' => false, 'partial' => false, 'nextCheck' => null], 'points' => ['checkedAt' => 1767139200, 'stale' => false, 'partial' => false, 'nextCheck' => null], 'registry' => ['checkedAt' => 1767139200, 'stale' => false, 'partial' => false, 'nextCheck' => null]];
    $watchDom = storefrontDom(view('domains::partials.summary', ['summary' => new DomainSummary('watch-known.example', null, 'watch', 'domain.registration.external', DnsSignalLabels::keys('external', false, false), false, false, [], $strings, '2099-01-01', 'date', 'Public registry', $checked, null, null, [], [], [], statusState: 'active')])->render());
    same(['Active'], $pills($watchDom), 'Watch detail with current registry facts shows Active only');
    same($strings['domain.dns.external'].' Checked Dec 31, 2025', $nameservers($watchDom), 'Nameservers row shows the current DNS value and its check date');
    same('Jan 1, 2099', preg_replace('/\s+/', ' ', trim($watchDom->query('//div[@class="domains-fact"][dt="Expires"]/dd')->item(0)?->textContent ?? '')), 'Expires row shows the registry date without its source');
    foreach (['Public registry', 'Date only', 'Registry expiry', 'Primary hostnames'] as $old) { expect(!str_contains($watchDom->document->saveHTML(), $old), 'Watch summary drops: '.$old); }
    // Notices stay notices; adoption and renewal state never become pills.
    $compact = static fn (DomainSummary $summary, array $with = []) => storefrontDom(view('domains::partials.summary', ['summary' => $summary, ...$with])->render());
    foreach (['watch.not_registered' => 'domain.registration.unknown', 'watch.no_coverage' => 'domain.registration.external'] as $state => $registration) {
        $notice = $compact($dto('notice.example', 'watch', $registration, $unknown, [$state]));
        same($state === 'watch.not_registered' ? ['Action required'] : [], $pills($notice), 'Watch summary pill: '.$state);
        same([$strings[$state]], array_map(fn ($node) => trim($node->textContent), iterator_to_array($notice->query('//p[@class="domains-notice"]'))), 'Watch summary keeps notice: '.$state);
    }
    $adopting = $dto('adopting.example', 'managed', 'domain.registration.platform', DnsSignalLabels::keys('platform', true, true), ['adopt.pending', 'adopt.provenance', 'domain.active'], 'adoption_pending');
    same(['Under review'], $pills($compact($adopting)), 'Pending outranks active');
    same([], $pills($compact($adopting, ['summaryOnManaged' => true])), 'Managed context leaves the pill to the page header');
    $offRow = $dom->query('//tbody/tr[td/a[normalize-space()="renewal-off.example"]]')->item(0);
    same('Auto-renew off', trim($dom->query('td[@data-label="Renewal"]', $offRow)->item(0)->textContent), 'Renewal column says Auto-renew off');
    same(1, substr_count($offRow->textContent, 'Auto-renew off'), 'Index row says Auto-renew off once');
    $pendingOff = new DomainSummary('pending-off.example', null, 'managed', 'domain.registration.unknown', $unknown, null, null, ['adopt.pending', 'adopt.renewal_off'], $strings, null, null, null, [], null, null, [], [], ['detail' => '/domains/pending-off.example'], renewalOff: true);
    $pendingRow = storefrontDom(view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator([$pendingOff], 1, 25)])->render())->query('//tbody/tr')->item(0);
    expect(!$pendingOff->renewalOffShown(), 'Pending verification never counts as a shown renewal off');
    same('Auto-renew on', trim($pendingRow->getElementsByTagName('td')->item(3)->textContent), 'Pending row Renewal column: the Under review pill explains the wait');
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
