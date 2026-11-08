<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline customer transfer results.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/Support/storefront-reader.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedTransferController, StorefrontController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{Rdap, TransferPrecheck};
extract(commerceFixture(true));
require dirname(__DIR__).'/Domains/routes/web.php';
require dirname(__DIR__).'/Domains/routes/owned.php';
storefrontViews();
$session = app('request')->session();
$state = (object) ['calls' => 0, 'fail' => false];
$transferBindings = storefrontTransferReader($state);
activeFixtureOffers($transferBindings, activeFixtureOffers($bindings));
$public = (object) ['status' => 200, 'states' => ['client transfer prohibited'], 'created' => '2020-01-01T00:00:00Z', 'transferred' => null];
$rdap = new Rdap(static function ($url) use ($public): array {
    $events = [['eventAction' => 'registration', 'eventDate' => $public->created], ['eventAction' => 'expiration', 'eventDate' => '2027-01-01T00:00:00Z']];
    if ($public->transferred) { $events[] = ['eventAction' => 'transfer', 'eventDate' => $public->transferred]; }
    return ['status' => str_contains($url, 'dns.json') ? 200 : $public->status,
        'body' => json_encode(str_contains($url, 'dns.json') ? ['services' => [[['com'], ['https://rdap.example.com/']]]]
            : ['objectClassName' => 'domain', 'ldhName' => 'transfer-placeholder.com', 'status' => $public->states, 'events' => $events])];
});
$eligibility = activeFixtureEligibility($transferBindings, $clock, new TransferPrecheck($rdap));
$store = activeFixtureStorefront($sellers, $prices, $native, $transferBindings, $eligibility, $clock);
$controller = new StorefrontController($store);
$server = App\Models\Server::findOrFail($tlds[0]->server_id);
$setting = $server->settings()->create(['key' => 'domains_transfer_endings', 'value' => 'com']);
$req = static function (array $input = [], string $method = 'POST') use ($session): Request {
    $r = Request::create('/domains/check', $method, $input, server: ['REMOTE_ADDR' => '192.0.2.77']);
    $r->setLaravelSession($session); return $r;
};
$key = FormKeys::issue($req(), 'search');
$check = static fn (bool $transfer = true) => $controller->check($req(['domain' => 'transfer-placeholder.com', '_request_key' => $key, 'mode' => $transfer ? 'transfer' : 'register']))->getData(true);
$assertRefusal = static function (array $result, string $reason) : void {
    expect(str_contains($result['html'], $reason), 'Transfer result explains: '.$reason.'; actual: '.strip_tags($result['html']));
    expect(!str_contains($result['html'], 'name="action"'), 'Refused transfer has no purchase action');
    expect(!str_contains($result['html'], '>Taken<'), 'Transfer refusal does not say Taken');
};
$public->created=$now->modify('-30 minutes')->format(DATE_ATOM);
$recent=$check();$dom=storefrontDom($recent['html']);
same('transfer_wait',$recent['status'],'A known registry lock is waiting, not an uncertain check');
expect(str_contains($recent['html'],$now->modify('-30 minutes')->modify('+60 days')->format('M j, Y \a\t H:i').' UTC'),'The customer sees the exact public lock end');
same('Wait to transfer',trim($dom->query('//*[contains(@class,"domains-result__status")]')->item(0)->textContent),'The rendered status describes a dated wait');
$public->created='2020-01-01T00:00:00Z';$public->status=503;$state->transferable='false';
$unknown=$check();same('transfer_unconfirmed',$unknown['status'],'Unknown registrar eligibility is not a hard refusal');
expect(str_contains($unknown['html'],'data-domains-retry')&&!str_contains($unknown['html'],'name="action"'),'Unconfirmed transfer offers Retry and no purchase');
$nativeSuccessful=true;echo "Native storefront transfer waits and unknown outcomes passed\n";
