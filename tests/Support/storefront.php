<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic customer DTOs only.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
function storefrontViews(): void
{
    app('router')->getRoutes()->refreshNameLookups();
    $dir = getenv('PAYMENTER_TEST_FIXTURE').'/resources/views/domains-harness';
    if (!is_dir($dir.'/layouts')) { mkdir($dir.'/layouts', 0700, true); }
    file_put_contents($dir.'/layouts/app.blade.php', '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>{{ $slot }}</body></html>');
    app('view')->getFinder()->prependLocation($dir);
    app('view')->addNamespace('domains', dirname(__DIR__, 2).'/Domains/resources/views');
    app('view')->share('errors', new Illuminate\Support\ViewErrorBag);
    $session = app('session')->driver(); $session->start(); app('request')->setLaravelSession($session);
}
function storefrontData(): array
{
    $endings = array_map(static fn ($tld) => ['tld' => $tld, 'price' => '$12.00', 'register' => '$12.00',
        'renew' => '$14.00', 'transfer' => '$11.00', 'terms' => [1, 3]], ['com', 'net', 'org']);
    $endings[1]['terms'] = range(1, 10); $endings[2]['terms'] = [1];
    $results = [];
    foreach (['available', 'transferable', 'taken', 'premium', 'held', 'error', 'idle'] as $i => $state) {
        $label = $i === 0 ? str_pad('placeholder', 63, 'a') : 'yourbusiness-placeholder';
        $results[] = ['domain' => placeholderComFixtureName($label.'.com'), 'sld' => $label, 'tld' => 'com', 'status' => $state,
            'price' => '$12.00', 'renew' => '$14.00', 'term' => 1, 'terms' => [1 => '$12.00', 3 => '$30.00', 10 => '$1,234.00'], 'key' => str_repeat('a', 64)];
    }
    $results[0]['term'] = 10; $results[0]['price'] = '$1,234.00';
    return ['endings' => $endings, 'name' => str_pad('placeholder', 63, 'a'), 'preferred' => 'com', 'sld' => str_pad('placeholder', 63, 'a'),
        'error' => null, 'notice' => null, 'results' => $results, 'years' => null, 'guest' => true,
        'testEnvironment' => false, 'checkKey' => str_repeat('b', 64)];
}
function storefrontDom(string $html): DOMXPath
{
    $dom = new DOMDocument; @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    return new DOMXPath($dom);
}
