<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require dirname(__DIR__).'/Domains/routes/web.php';
require dirname(__DIR__).'/Domains/routes/owned.php';

use Illuminate\Pagination\LengthAwarePaginator;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary, OwnedDomain};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaryStrings, WatchTransferStrings};

storefrontViews();
app('view')->share('keys', array_fill_keys(['setup', 'code', 'renew', 'auto-renew'], str_repeat('a', 64)));
$label = str_pad('placeholder', 63, 'a');
$name = placeholderComFixtureName($label.'.com');
$summary = new DomainSummary($name, null, 'watch', 'domain.registration.external', [], true, true,
    ['watch.ns_changed'], DomainSummaryStrings::all(), '2027-01-15', 'date', 'Public registry', [], null, null,
    [], [$name], ['detail'=>'/domains/checks/watch/1', 'support'=>'/tickets/create', 'compareTransfer'=>'/domains/checks/watch/1/transfer']);
$domain = new OwnedDomain($name, 'Active', 'ok', '2027-01-15', 'On', '$14.00',
    ['show'=>'/domains/1', 'setupSave'=>'/domains/1/setup', 'codeSave'=>'/domains/1/code'], [],
    ['required'=>false, 'contacts'=>false, 'nameservers'=>true, 'privacy'=>false, 'defaults'=>false, 'maxNameservers'=>12],
    ['open'=>true, 'received'=>false, 'phase'=>'editing', 'deadline'=>null, 'unchecked'=>false], [], false);
$search = storefrontData();
$search['results'][0] = [...$search['results'][0], 'domain'=>$name, 'sld'=>$label, 'tld'=>'com'];
$transfer = ['id'=>1, 'name'=>$name, 'revision'=>'1', 'canCheck'=>true, 'state'=>'eligible',
    'price'=>'$11.00', 'renew'=>'$14.00', 'strings'=>WatchTransferStrings::all(), 'key'=>str_repeat('a', 64)];
$pages = [
    'owned'=>view('domains::show', ['domain'=>$domain, 'summary'=>$summary])->render(),
    'watch'=>view('domains::watch-notices', ['summary'=>$summary, 'name'=>$name, 'kind'=>'watch', 'id'=>1,
        'editable'=>false, 'preferences'=>['account'=>['enabled'=>true, 'revision'=>1], 'target'=>['enabled'=>true, 'revision'=>1]],
        'notices'=>[], 'portal'=>[], 'key'=>null])->render(),
    'list'=>view('domains::index', ['domains'=>new LengthAwarePaginator([$summary], 1, 25)])->render(),
    'watch-transfer'=>view('domains::watch-transfer', $transfer)->render(),
    'search'=>view('domains::search', $search)->render(),
    'transfer'=>view('domains::transfer', $search)->render(),
    'setup'=>view('domains::setup', ['domain'=>$domain])->render(),
    'code'=>view('domains::code', ['domain'=>$domain])->render(),
    'checkout'=>view('domains::checkout-line', ['domain'=>['name'=>$name, 'action'=>'Registration', 'years'=>1,
        'renewalPrice'=>'$14.00', 'editUrl'=>'/cart/1']])->render(),
];
// Optional artifacts use the same real Blade bytes as the registered regression test.
if (isset($argv[1])) {
    expect(is_dir($argv[1]), 'Existing render output directory required');
    foreach ($pages as $page=>$html) { writeRenderArtifact($argv[1].'/'.$page.'.html', $html); }
}
foreach ($pages as $page=>$html) {
    $dom = storefrontDom($html);
    $nodes = $dom->query('//*[contains(., "'.$name.'") and not(*[contains(., "'.$name.'")]) and not(self::script or self::style or self::html or self::body)]');
    expect($nodes->length > 0, $page.' must actually display the long domain');
    foreach ($nodes as $node) {
        same(1, $dom->query('ancestor::*[contains(concat(" ", normalize-space(@class), " "), " domains ")][1]', $node)->length,
            $page.' domain text inherits the shared wrapping rule');
    }
    $styles = implode("\n", array_map(static fn($node)=>$node->textContent, iterator_to_array($dom->query('//style'))));
    expect(preg_match('/(?:^|[\s}])\.domains\s*\{[^}]*overflow-wrap:\s*anywhere\s*;/s', $styles) === 1,
        $page.' shared wrapping must reduce flex and grid minimum content width');
    expect(preg_match('/\.domains\s+\.domains-btn\s*\{[^}]*white-space:\s*nowrap\s*;/s', $styles) === 1,
        $page.' buttons retain their unbroken labels');
}
$nativeSuccessful = true;
echo "Long domain text inherits shared wrapping across nine storefront views.\n";
