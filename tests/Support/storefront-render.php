<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline visual harness, not an installed extension.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/storefront.php';
require dirname(__DIR__, 2).'/Domains/routes/web.php';
storefrontViews();
$shell = file_get_contents(getenv('PAYMENTER_TEST_FIXTURE').'/ui32-shell.html');
$data = storefrontData();
$pages = ['search' => view('domains::search', $data)->render(),
    'pricing' => view('domains::pricing', ['rows' => $data['endings'], 'guest' => true, 'testEnvironment' => false])->render()];
$data['results'] = array_values(array_filter($data['results'], static fn ($r) => in_array($r['status'], ['held', 'error'], true)));
$data['results'][1]['message'] = 'Too many searches. Wait a minute, then retry.';
$data['testEnvironment'] = true;
session()->put('domains_notice', 'We couldn’t add that name to your cart. Check the latest results below and try again.');
$pages['errors-held-rate'] = view('domains::search', $data)->render();
session()->forget('domains_notice');
$data['results'] = []; $data['name'] = 'éxample'; $data['sld'] = null;
$data['error'] = 'Use only letters a–z, numbers and hyphens. International characters aren’t supported.';
$pages['parse-error-focus'] = view('domains::search', $data)->render();
foreach ($pages as $name => $html) {
    preg_match('#<body>(.*)</body>#s', $html, $body);
    // Static fixture: never execute search fetch, Livewire or theme scripts.
    $content = preg_replace('#<script\b[^>]*>.*?</script>#s', '', $body[1]);
    writeRenderArtifact(getenv('UI32_OUTPUT').'/'.$name.'.html', str_replace('@@SLOT@@', $content, $shell));
}
$nativeSuccessful = true;
echo "Real Blade synthetic storefront pages rendered.\n";
