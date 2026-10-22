<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real Blade customer presentation cases.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary,DomainSummaryBadges};
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
        same(3, $dom->query('//td[@data-label="Renew, per year"]')->length, 'D7 mobile renew labels specify annual unit');
        expect(!str_contains($html, '/yr'), 'D7 pricing never duplicates the annual suffix');
        expect(str_contains($html, 'Prices plus tax where applicable.</p>'), 'D7 annual footnote is one sentence');
        expect(!str_contains($html, 'Longer terms are priced'), 'D7 annual pricing has no longer-term footnote');
    }
    $rows = $data['endings']; $rows[0]['terms'] = [2]; $rows[1]['terms'] = []; $rows[1]['renew'] = null;
    $html = $render($rows, false); $dom = storefrontDom($html);
    same(5, $dom->query('//thead/tr/th')->length, 'D7 longer or absent registration terms retain column');
    same(3, $dom->query('//td[@data-label="Register for"]')->length, 'D7 conditional column retains every row cell');
    same('—', trim($dom->query('//td[@data-label="Renew, per year"]')->item(1)->textContent), 'D7 missing renewal price uses em dash');
    expect(str_contains($html, 'Longer terms are priced per term; search for a name to see each total.'), 'D7 longer single term qualifies for footnote');
    $rows[0]['terms'] = [1];
    expect(!str_contains($render($rows, false), 'Longer terms are priced'), 'D7 absent registration terms do not invent longer terms');
    // The index describes the visible page, including filtered/empty pages, not hidden accounts.
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
        same($tests ? 1 : 0, $dom->query('//*[@class="domains-testbar"]')->length, 'D7 index all/some/none/empty banner');
        if ($tests) { expect(str_contains($html, $tests === count($flags) ? $all : $some), 'D7 index selects all versus some copy'); }
        same($tests, $dom->query('//tbody/tr/td/span[normalize-space()="Test"]')->length, 'D7 index tags only test rows');
    }
    $emptyFiltered = view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator([], 50, 25)])->render();
    same(0, storefrontDom($emptyFiltered)->query('//*[@class="domains-testbar"]')->length, 'D7 empty visible page ignores hidden totals');
    // List density: state first, known evidence next, then one chip for every missing axis. Detail keeps each axis.
    $strings = DomainSummaryStrings::all();
    $dto = static fn (string $name, string $kind, string $registration, array $dnsKeys, array $states) => new DomainSummary($name, null, $kind,
        $registration, $dnsKeys, null, null, $states, $strings, null, null, null, [], null, null, [], [], ['detail' => '/domains/'.$name], renewalOff: in_array('adopt.renewal_off', $states, true));
    $unknown = DnsSignalLabels::keys(null, null, null);
    $rows = [
        'active-unchecked' => ['domain.registration.platform', $unknown, ['domain.active', 'watch.not_checked'], ['domain.active', 'domain.registration.platform', 'watch.not_checked']],
        'renewal-off' => ['domain.registration.platform', $unknown, ['domain.active', 'adopt.renewal_off', 'watch.not_checked'], ['domain.active', 'domain.registration.platform', 'watch.not_checked']],
        'held' => ['domain.registration.unknown', $unknown, ['domain.review', 'watch.not_checked'], ['domain.review', 'watch.not_checked']],
        'unknown-current' => ['domain.registration.unknown', $unknown, ['domain.review'], ['domain.review', 'domain.details.partial']],
        'stale' => ['domain.registration.platform', DnsSignalLabels::keys(null, null, null, partial: true), ['domain.active', 'watch.stale', 'watch.partial'], ['domain.active', 'domain.registration.platform', 'watch.stale']],
        'partly-known' => ['domain.registration.platform', DnsSignalLabels::keys('platform', true, null, partial: true), ['domain.active'], ['domain.active', 'domain.registration.platform', 'domain.dns.platform', 'domain.points.website', 'domain.details.partial']],
        'known' => ['domain.registration.platform', DnsSignalLabels::keys('platform', false, false), ['domain.active'], ['domain.active', 'domain.registration.platform', 'domain.dns.platform', 'domain.points.platform_empty']],
    ];
    $summaries = array_map(fn ($name) => $dto($name.'.example', 'managed', ...array_slice($rows[$name], 0, 3)), array_keys($rows));
    $watches = [$dto('watch-unchecked.example', 'watch', 'domain.registration.unknown', $unknown, ['watch.not_checked']), $dto('watch-known.example', 'watch', 'domain.registration.external', DnsSignalLabels::keys('external', false, false), []), $dto('related-off.example', 'managed', 'domain.registration.platform', DnsSignalLabels::keys('platform', false, false), ['domain.active', 'adopt.renewal_off'])];
    $html = view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator($summaries, count($summaries), 25), 'watches' => new \Illuminate\Pagination\LengthAwarePaginator($watches, count($watches), 25)])->render();
    $dom = storefrontDom($html);
    $chips = static fn ($cell) => array_map(fn ($span) => [trim($span->textContent), $span->getAttribute('class')], iterator_to_array($dom->query('.//span[contains(@class,"domains-badge")]', $cell)));
    foreach (array_keys($rows) as $i => $name) {
        $cell = $dom->query('//tbody/tr['.($i + 1).']/td[@data-label="Registration and DNS"]')->item(0);
        same(array_map(fn ($key) => $strings[$key], $rows[$name][3]), array_column($chips($cell), 0), 'List chips: state first, known evidence, one gap chip: '.$name);
        $unknownAxes = count(array_intersect([$rows[$name][0], ...$rows[$name][1]], ['domain.registration.unknown', 'domain.dns.unknown', 'domain.points.unknown', 'domain.points.partial']));
        same($unknownAxes ? 1 : 0, count(array_filter($chips($cell), fn ($chip) => in_array($chip[0], [$strings['watch.not_checked'], $strings['watch.stale'], $strings['domain.details.partial']], true))), 'List shows at most one missing-evidence chip: '.$name);
    }
    same(0, preg_match('/(Registration|DNS|Connection) details unavailable|Some checks are unavailable/', $dom->query('//table')->item(0)->textContent), 'List never repeats per-axis unknown chips');
    same(1, count(array_filter($chips($dom->query('//tbody/tr[3]')->item(0)), fn ($chip) => str_contains($chip[1], 'domains-tone-muted'))), 'Three unknown axes render exactly one muted chip');
    same('domains-badge domains-tone-muted', $chips($dom->query('//tbody/tr[4]')->item(0))[1][1], 'Collapsed gap chip is muted');
    $related = $dom->query('//section[@class="domains-related"]//div[contains(@class,"domains-summary-badges")]');
    same([$strings['watch.not_checked']], array_column($chips($related->item(0)), 0), 'Related watch rows use the same collapse rule');
    same(array_map(fn ($key) => $strings[$key], ['domain.registration.external', 'domain.dns.external', 'domain.points.external_empty']), array_column($chips($related->item(1)), 0), 'Related watch rows keep known evidence');
    $detail = view('domains::partials.summary', ['summary' => $summaries[2]])->render();
    foreach (['domain.registration.unknown', 'domain.dns.unknown', 'domain.points.unknown', 'watch.not_checked'] as $key) { expect(str_contains($detail, '>'.$strings[$key].'<'), 'Detail keeps every axis: '.$key); }
    $fresh = ['delegation' => ['checkedAt' => null, 'stale' => false, 'partial' => false, 'nextCheck' => 1767225600], 'points' => ['checkedAt' => 1767139200, 'stale' => true, 'partial' => true, 'nextCheck' => null]];
    $detailDom = storefrontDom(view('domains::partials.summary', ['summary' => new DomainSummary('held.example', null, 'managed', 'domain.registration.unknown', $unknown, null, null, ['domain.review', 'watch.not_checked'], $strings, null, null, null, $fresh, null, null, [], [], [])])->render());
    same('Check details and timestamps', trim($detailDom->query('//details[@class="domains-checks"]/summary')->item(0)?->textContent ?? ''), 'Detail keeps the check disclosure');
    $facts = array_map(fn ($node) => preg_replace('/\s+/', ' ', trim($node->textContent)), iterator_to_array($detailDom->query('//details[@class="domains-checks"]//dd')));
    same(['Not checked yet Next check Jan 1, 2026, 00:00 UTC', 'Checks are out of date Last checked Dec 31, 2025, 00:00 UTC'], $facts, 'Detail disclosure keeps freshness and timestamps');
    // Related rows keep their exclusions: notices stay notices, and managed context hides adoption chips.
    $compact = static fn (DomainSummary $summary, array $with = []) => storefrontDom(view('domains::partials.summary', ['summary' => $summary, 'summaryCompact' => true, ...$with])->render());
    $texts = static fn ($dom, $path = '//span[contains(@class,"domains-badge")]') => array_map(fn ($node) => trim($node->textContent), iterator_to_array($dom->query($path)));
    foreach (['watch.not_registered' => 'domain.registration.unknown', 'watch.no_coverage' => 'domain.registration.external'] as $state => $registration) {
        $dom = $compact($dto('notice.example', 'watch', $registration, $unknown, [$state]));
        same([...($state === 'watch.no_coverage' ? [$strings[$registration]] : []), $strings['domain.details.partial']], $texts($dom), 'Related row chips exclude '.$state);
        same([$strings[$state]], $texts($dom, '//p[@class="domains-notice"]'), 'Related row keeps notice: '.$state);
    }
    $adopting = $dto('adopting.example', 'managed', 'domain.registration.platform', DnsSignalLabels::keys('platform', true, true), ['adopt.pending', 'adopt.provenance', 'domain.active']);
    same(array_map(fn ($key) => $strings[$key], ['adopt.pending', 'adopt.provenance', 'domain.active', 'domain.dns.platform', 'domain.points.both']), $texts($compact($adopting)), 'Pending adoption hides registration, keeps state chips first');
    same(array_map(fn ($key) => $strings[$key], ['domain.active', 'domain.dns.platform', 'domain.points.both']), $texts($compact($adopting, ['summaryOnManaged' => true])), 'Managed context hides adoption chips');
    // The index Renewal column already says "Renewal off", so that row has no matching chip. Views without the column keep it.
    $index = storefrontDom($html); $offRow = $index->query('//tbody/tr[td/a[normalize-space()="renewal-off.example"]]')->item(0);
    same('Renewal off', trim($index->query('td[@data-label="Renewal"]', $offRow)->item(0)->textContent), 'Renewal column says renewal off');
    same(1, substr_count($offRow->textContent, 'Renewal off'), 'Index row says renewal off once');
    $knownOff = [$strings['domain.active'], $strings['adopt.renewal_off'], ...array_map(fn ($key) => $strings[$key], ['domain.registration.platform', 'domain.dns.platform', 'domain.points.platform_empty'])];
    same($knownOff, $texts($index, '//section[@class="domains-related"]/section[h3[normalize-space()="related-off.example"]]//div[contains(@class,"domains-summary-badges")]/span'), 'Related rows have no Renewal column and keep the chip');
    same($knownOff, $texts($compact($watches[2])), 'Compact summary without a Renewal column keeps the chip');
    foreach ([[], ['summaryOnManaged' => true, 'summaryFinancials' => false]] as $with) {
        expect(in_array($strings['adopt.renewal_off'], $texts(storefrontDom(view('domains::partials.summary', ['summary' => $watches[2], ...$with])->render()), '//div[contains(@class,"domains-summary-badges")]/span'), true), 'Detail summary keeps the renewal off chip: '.json_encode($with));
    }
    $silent = new DomainSummary('silent-off.example', null, 'managed', 'domain.registration.platform', DnsSignalLabels::keys('platform', false, false), null, null, ['domain.active', 'adopt.renewal_off'], $strings, null, null, null, [], null, null, [], [], ['detail' => '/domains/silent-off.example']);
    $silentRow = storefrontDom(view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator([$silent], 1, 25)])->render())->query('//tbody/tr')->item(0);
    same(1, substr_count($silentRow->textContent, 'Renewal off'), 'Chip stays when the Renewal column does not say renewal off');
    $pendingOff = new DomainSummary('pending-off.example', null, 'managed', 'domain.registration.unknown', $unknown, null, null, ['adopt.pending', 'adopt.renewal_off'], $strings, null, null, null, [], null, null, [], [], ['detail' => '/domains/pending-off.example'], renewalOff: true);
    $pendingRow = storefrontDom(view('domains::index', ['domains' => new \Illuminate\Pagination\LengthAwarePaginator([$pendingOff], 1, 25)])->render())->query('//tbody/tr')->item(0);
    expect(!$pendingOff->renewalOffShown(), 'Pending verification never counts as a shown renewal off');
    same('Pending verification', trim($pendingRow->getElementsByTagName('td')->item(3)->textContent), 'Pending row Renewal column');
    same(1, substr_count($pendingRow->textContent, 'Renewal off'), 'Pending row keeps the renewal off chip');
    $gapEvidence = ['domain.registration.unknown', ...$unknown];
    foreach ([[['watch.partial', 'watch.stale', 'domain.active', 'watch.not_checked'], 'watch.not_checked'], [['watch.partial', 'watch.stale'], 'watch.stale'], [['watch.partial'], 'watch.partial'], [[], 'domain.details.partial']] as [$states, $chip]) {
        same([...array_values(array_diff($states, DomainSummaryBadges::FRESHNESS)), $chip], DomainSummaryBadges::compact($gapEvidence, $states), 'Freshness priority: '.$chip);
    }
    // No combination of evidence earns a label the summary did not supply, and only one chip covers missing axes.
    foreach ([null, 'platform', 'external'] as $d) { foreach ([null, true, false] as $w) { foreach ([null, true, false] as $e) { foreach ([false, true] as $partial) {
        foreach (['domain.registration.platform', 'domain.registration.unknown'] as $registration) { foreach ([[], ['domain.active'], ['domain.active', 'watch.not_checked'], ['watch.stale', 'watch.partial'], ['watch.partial', 'watch.not_checked', 'watch.stale'], ['watch.partial'], ['domain.active', 'adopt.renewal_off', 'watch.stale']] as $states) {
            $evidence = [$registration, ...DnsSignalLabels::keys($d, $w, $e, partial: $partial)];
            $badges = $dto('x.example', 'managed', $registration, [], $states)->listBadges($evidence, $states);
            $gaps = array_intersect($evidence, DomainSummaryBadges::GAPS); $fresh = array_intersect($states, DomainSummaryBadges::FRESHNESS);
            same([], array_diff($badges, $evidence, $states, ['domain.details.partial']), 'Collapse never invents a label');
            same(array_values(array_diff($badges, ['adopt.renewal_off'])), $dto('x.example', 'managed', $registration, [], $states)->listBadges($evidence, $states, renewalColumn: true), 'A Renewal column drops only the renewal off chip');
            same([], array_intersect($badges, DomainSummaryBadges::GAPS), 'Per-axis unknown chips collapse');
            same($gaps || $fresh ? 1 : 0, count(array_intersect($badges, [...DomainSummaryBadges::FRESHNESS, 'domain.details.partial'])), 'One evidence-gap chip at most');
            same(array_values(array_diff($states, DomainSummaryBadges::FRESHNESS)), array_slice($badges, 0, count($states) - count($fresh)), 'State chips first');
            if ($d === null || $w === null || $e === null || $partial) { same([], array_intersect($badges, ['domain.points.platform_empty', 'domain.points.external_empty']), 'Unknown never earns an empty-case label'); }
            if ($d === null) { same([], array_intersect($badges, ['domain.dns.platform', 'domain.dns.external']), 'Unknown DNS never earns a DNS label'); }
            if ($w !== true && $e !== true) { same([], array_intersect($badges, ['domain.points.website', 'domain.points.email', 'domain.points.both']), 'Unknown pointers never earn a positive label'); }
        } }
    } } } }
    // Every summary key has the D3 vocabulary on both the index and shared detail badges.
    $tones = [
        'ok' => ['domain.registration.platform','domain.dns.platform','domain.points.website','domain.points.email','domain.points.both','domain.points.platform_empty','domain.active'],
        'info' => ['domain.registration.external','domain.dns.external','domain.points.mixed','domain.points.external_empty','adopt.pending','adopt.provenance','adopt.activity','domain.transfer','domain.pending','domain.processing','watch.ns_pending','ns_pending'],
        'warn' => ['domain.awaiting_payment','domain.review','watch.expired','registry_expiry_reached','watch.ns_changed','ns_changed','watch.stale','watch.partial','domain.points.partial'],
        'bad' => ['domain.expired','domain.redemption','watch.redemption'],
        'muted' => ['domain.registration.unknown','domain.dns.unknown','domain.points.unknown','domain.details.partial','domain.points.help','domain.cancelled','adopt.renewal_off','watch.paused','watch.retired','watch.not_checked','watch.unknown','watch.no_coverage','watch.not_registered','dns_unavailable','expiry_unavailable','adopt.pending_notice','watch.ns_changed_notice','future.unknown'],
    ];
    foreach ($tones as $tone => $keys) {
        foreach ($keys as $key) {
            $summary = new \Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary('tone-example-placeholder.com', null, 'managed',
                $key, [], null, null, [], [...\Paymenter\Extensions\Servers\Domains\Services\DomainSummaryStrings::all(), $key => 'Tone fixture'], null, null, null, [], null, null,
                [['id' => 1, 'label' => 'Related hosting', 'url' => '/services/1']], [], ['detail' => '/domains/1']);
            same($tone, $summary->tone($key), 'D3 summary key mapping: '.$key);
            foreach (['domains::index', 'domains::partials.summary'] as $view) {
                $html = view($view, ['summary' => $summary, 'domains' => new \Illuminate\Pagination\LengthAwarePaginator([$summary], 1, 25)])->render();
                // The list folds unknown axes into the muted gap chip; the shared detail badges keep each key.
                [$shown, $label] = $view === 'domains::index' && in_array($key, DomainSummaryBadges::GAPS, true) ? ['muted', $strings['domain.details.partial']] : [$tone, 'Tone fixture'];
                same(1, storefrontDom($html)->query('//span[contains(@class,"domains-tone-'.$shown.'") and normalize-space()="'.$label.'"]')->length, 'D3 rendered tone: '.$view.' '.$key);
                expect(str_contains($html, 'Related hosting'), 'W5 related services survive tone merge');
            }
        }
    }
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
