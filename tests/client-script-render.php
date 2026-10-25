<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real Blade with synthetic customer DTOs, no provider I/O.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require dirname(__DIR__).'/Domains/routes/web.php';
require dirname(__DIR__).'/Domains/routes/owned.php';

use Illuminate\Pagination\LengthAwarePaginator;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary, OwnedDomain};

storefrontViews();
$out = $argv[1];
expect(is_dir($out), 'Output directory required');
$domain = new OwnedDomain('example.com', 'Active', 'ok', '2027-10-01', 'On', '$14.00',
    ['show' => '/domains/1'], [], ['contacts' => false, 'required' => false], [], [], false);
$summary = new DomainSummary('example.com', null, 'watch', 'domain.registration.external', [], null, null,
    [], [], '2027-10-01', 'date', null, [], null, null, [], [], []);
$cases = [
    'partials.client-script' => [],
    'index' => ['domains' => new LengthAwarePaginator([], 0, 25)],
    'show' => ['domain' => $domain, 'keys' => []],
    'setup' => ['domain' => $domain],
    'search' => storefrontData(),
    'transfer' => storefrontData(),
    'pricing' => ['rows' => storefrontData()['endings'], 'guest' => true, 'testEnvironment' => false],
    'watch-notices' => ['summary' => $summary, 'name' => 'example.com', 'kind' => 'watch', 'id' => 1,
        'editable' => false, 'notices' => [], 'portal' => []],
];
foreach ($cases as $view => $data) {
    writeRenderArtifact($out.'/'.$view.'.html', view('domains::'.$view, $data)->render());
}
foreach ([
    'company' => ['Example Hosting (Test)', 'Fallback Cloud'],
    'app' => ['', 'Example Cloud (Local)'],
    'neutral' => ['', ''],
    'quoted' => ['Example\'s "Cloud" <script> & Co', ''],
] as $case => [$company, $appName]) {
    config(['settings.company_name' => $company, 'app.name' => $appName]);
    writeRenderArtifact($out.'/label-'.$case.'.html', view('domains::partials.client-script')->render());
}
$nativeSuccessful = true;
echo "Rendered shared client script, all seven including views, and company-name cases.\n";
