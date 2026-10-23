<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real Blade customer presentation cases.
declare(strict_types=1);
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
            $dtos[] = new \Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary('example-'.$i.'.com', null, 'managed',
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
    // Every summary key has the D3 vocabulary on both the index and shared detail badges.
    $tones = [
        'ok' => ['domain.registration.platform','domain.dns.platform','domain.points.website','domain.points.email','domain.points.both','domain.points.platform_empty','domain.active'],
        'info' => ['domain.registration.external','domain.dns.external','domain.points.mixed','domain.points.external_empty','adopt.pending','adopt.provenance','adopt.activity','domain.transfer','domain.pending','domain.processing','watch.ns_pending','ns_pending'],
        'warn' => ['domain.awaiting_payment','domain.review','watch.expired','registry_expiry_reached','watch.ns_changed','ns_changed','watch.stale','watch.partial','domain.points.partial'],
        'bad' => ['domain.expired','domain.redemption','watch.redemption'],
        'muted' => ['domain.registration.unknown','domain.dns.unknown','domain.points.unknown','domain.points.help','domain.cancelled','adopt.renewal_off','watch.paused','watch.retired','watch.not_checked','watch.unknown','watch.no_coverage','watch.not_registered','dns_unavailable','expiry_unavailable','adopt.pending_notice','watch.ns_changed_notice','future.unknown'],
    ];
    foreach ($tones as $tone => $keys) {
        foreach ($keys as $key) {
            $summary = new \Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary('tone-example.com', null, 'managed',
                $key, [], null, null, [], [...\Paymenter\Extensions\Servers\Domains\Services\DomainSummaryStrings::all(), $key => 'Tone fixture'], null, null, null, [], null, null,
                [['id' => 1, 'label' => 'Related hosting', 'url' => '/services/1']], [], ['detail' => '/domains/1']);
            same($tone, $summary->tone($key), 'D3 summary key mapping: '.$key);
            foreach (['domains::index', 'domains::partials.summary'] as $view) {
                $html = view($view, ['summary' => $summary, 'domains' => new \Illuminate\Pagination\LengthAwarePaginator([$summary], 1, 25)])->render();
                same(1, storefrontDom($html)->query('//span[contains(@class,"domains-tone-'.$tone.'") and normalize-space()="Tone fixture"]')->length, 'D3 rendered tone: '.$view.' '.$key);
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
