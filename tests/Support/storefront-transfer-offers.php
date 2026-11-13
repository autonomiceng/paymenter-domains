<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline query counts and display parity.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/commerce.php';
require __DIR__.'/native-commerce.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\Storefront;
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

extract(commerceFixture(true));
$offerPorts=$ports;
require __DIR__.'/owner-pages.php';
require_once __DIR__.'/installed-module-routing.php';
[$prices,$native,$sellers]=installedModuleRouting($bindings,$clock,$offerPorts,$clock,new Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck(new Paymenter\Extensions\Servers\Domains\Services\Rdap(static fn()=>throw new LogicException('No transfer precheck on display.'))));
// Finish installed route construction before measuring cold display reads.
Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::routed();
$store = app(Storefront::class); $actor = commerceUser();
$controller = new OwnedDomainController($owners);
$request = Request::create('/domains', 'GET'); $request->setUserResolver(fn () => $actor);
$measure = static function (Closure $read): array {
    DB::flushQueryLog(); DB::enableQueryLog();
    try {
        $value = $read(); $queries = DB::getQueryLog();
        $gate = array_filter($queries, static fn ($q) => $q['query'] === 'select * from "settings" where "settingable_type" is null and "settingable_id" is null and "key" = ?'
            && $q['bindings'] === ['domains_offer_upgrade']);
        return [$value, count($queries), count($gate)];
    }
    finally { DB::disableQueryLog(); DB::flushQueryLog(); }
};
// A valid early offer must stop before any later enabled policy, even on the actual index.
Cache::flush(); [$page, $small] = $measure(fn () => $controller->index($request));
same(true, $page->getData()['transfersOffered'], 'Cold index has a transfer offer');
$extra = [];
for ($i = 0; $i < 1499; $i++) {
    $extra[] = ['tld' => 'z'.chr(97 + intdiv($i, 676)).chr(97 + intdiv($i % 676, 26)).chr(97 + $i % 26), 'transfer_enabled' => true];
}
foreach (array_chunk($extra, 100) as $chunk) { DB::table('domains_sales_policies')->insert($chunk); }
Cache::flush(); [$page, $large] = $measure(fn () => $controller->index($request));
same(true, $page->getData()['transfersOffered'], 'Large cold index retains the offer');
echo "Cold index queries: one ending $small, 1500 endings $large.\n";
same($small, $large, 'Cold index stops at the first valid transfer offer');
DB::table('domains_sales_policies')->where('tld', '!=', 'com')->delete();

$warmCounts=[];
$parity = static function (bool $expected, string $label) use ($store, $measure, &$warmCounts): void {
    Cache::flush();
    same($expected, $store->hasTransferOffers(), $label.' cold existence');
    $rows = $store->endings();
    same($expected, collect($rows)->contains(fn ($row) => $row['transfer'] !== null), $label.' full catalogue');
    [$warm, $queries, $gateQueries] = $measure(fn () => $store->hasTransferOffers());
    same($expected, $warm, $label.' warm existence');
    $warmCounts[]=['label'=>$label,'counts'=>[$queries,$gateQueries]];
};
$parity(true, 'Test account');
[$offered, $queries, $gateQueries] = $measure(fn () => $store->hasTransferOffers());
same(true, $offered, 'Warm positive is retained');$warmCounts[]=['label'=>null,'counts'=>[$queries,$gateQueries]];
// Retained native stock corruption keeps the same display lag as endings(), including negative rows.
$product = $native->product($tlds[0], 'transfer');
try{$product->update(['stock'=>0]);expect(false,'Current native saves refuse finite domain stock');}
catch(Illuminate\Validation\ValidationException $error){expect(isset($error->errors()['stock']),'Native stock guard identifies the refused field');}
$product->refresh();
DB::table('products')->where('id',$product->id)->update(['stock'=>0]);
same(true, $store->hasTransferOffers(), 'Warm positive survives native withdrawal within TTL');
$clock->instant = $now->modify('+30 seconds');
same(false, $store->hasTransferOffers(), 'Exact TTL expiry rechecks native withdrawal');
$parity(false, 'No transfer stock');
DB::table('products')->where('id',$product->id)->update(['stock'=>null]);
same(false, $store->hasTransferOffers(), 'Warm negative survives native restoration within TTL');
$clock->instant = $now->modify('+60 seconds'); $parity(true, 'Restored stock');
// A separate Boolean cache must not disagree with endings() when its full projection is cold.
Cache::flush(); DB::table('products')->where('id',$product->id)->update(['stock'=>0]);
same(false, $store->hasTransferOffers(), 'Cold negative without a full projection');
DB::table('products')->where('id',$product->id)->update(['stock'=>null]);
same(true, $store->hasTransferOffers(), 'Cold restoration cannot reuse a separate negative cache');
same(true, collect($store->endings())->contains(fn ($row) => $row['transfer'] !== null), 'Cold restored catalogue agrees');
// A writer must bypass committed rows and must not publish its temporary result.
DB::beginTransaction();
$savedIntent=$tlds[0]->fresh()->catalogue_intent;
DB::table('domains_tlds')->where('id',$tlds[0]->id)->update(['catalogue_intent'=>json_encode([...Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($savedIntent),'stops'=>['transfer']])]);
same(false, $store->hasTransferOffers(), 'Transaction sees its own disabled TLD');
DB::rollBack(); same(true, $store->hasTransferOffers(), 'Rollback retains committed display');
foreach (['enabled', 'renew_enabled', 'transfer_enabled'] as $flag) {
    activeFixtureStops($sellers,$tlds[0],$flag==='enabled'?['register','renew','transfer']:[str_replace('_enabled','',$flag)]);
    $parity(false, 'Disabled TLD '.$flag);
    activeFixtureStops($sellers,$tlds[0],[]);
}
DB::table('domains_sales_policies')->where('tld', 'com')->update(['transfer_enabled' => false]);
$parity(false, 'Registration-only policy');
DB::table('domains_sales_policies')->where('tld', 'com')->update(['transfer_enabled' => true]);
foreach (['transfer', 'renew'] as $action) {
    $pricesBefore=DB::table('domains_prices')->where('server_id',$tlds[0]->server_id)->where('action',$action)->get();
    foreach($pricesBefore as $row){$cost=Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode(costEvidence($row));$change=$action==='renew'?['enabled'=>false,'total'=>null]:['total'=>'999.0000'];DB::table('domains_prices')->where('id',$row->id)->update([...costColumns(json_encode([...$cost,...$change]))]);}
    $parity(false, 'Held '.$action.' price');
    foreach($pricesBefore as $row){DB::table('domains_prices')->where('id',$row->id)->update([...costColumns(costEvidence($row))]);}
}
// A below-cost retained annual renewal stays billable; an unavailable annual path above still closes new transfers.
$renewalRow=DB::table('domains_prices')->where('server_id',$tlds[0]->server_id)->where('action','renew')->sole();
$renewalCost=Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode(costEvidence($renewalRow));
DB::table('domains_prices')->where('id',$renewalRow->id)->update([...costColumns(json_encode([...$renewalCost,'total'=>'999.0000']))]);
$parity(true,'Retained renewal below cost');
$renewalBinding=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole());
same('below_cost',$caps->holder($renewalBinding,'com')->staffAlert,'Retained renewal margin keeps its staff alert');
DB::table('domains_prices')->where('id',$renewalRow->id)->update([...costColumns(costEvidence($renewalRow))]);
// A failing earlier candidate must not hide a later valid offer.
DB::table('domains_sales_policies')->insert(['tld' => 'aaa', 'transfer_enabled' => true]);
$parity(true, 'Unavailable earlier seller');
DB::table('domains_sales_policies')->where('tld', 'aaa')->delete();
$demoServer = App\Models\Server::create(['name' => 'Synthetic', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
$bindings->configure($demoServer->id, 'enom', Paymenter\Extensions\Servers\Domains\Data\Environment::Test, ['token' => 'demo-enom']);
expect(Paymenter\Extensions\Servers\Domains\Support\DemoData::present(), 'Real demo account marker is installed');
$parity(true, 'Offline demo display');
config(['domains.enom_identity.environment' => 'test']);
$parity(true, 'Demo with test deployment');
$savedRows = $store->endings(); $before = $reads->calls;
$clock->instant = $now->modify('+1 day'); $parity(false, 'Expired acquisition observations');
same([], $store->endings(), 'Expired current evidence closes new transfer offers');
$holder=$caps->holder(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole()),'com');
same('10.40',$holder->retail,'Age preserves contractual holder renewal retail');
same($before, $reads->calls, 'Older transfer prices require no provider reads');
DB::table('domains_sales_policies')->delete(); $parity(false, 'No policies');
if($transferOfferChecks==='queries'){
    echo 'Warm transfer-existence counts: '.json_encode($warmCounts)."\n";
    foreach($warmCounts as $sample){[$queries,$gateQueries]=$sample['counts'];$label=$sample['label'];
        // The catalogue cache fence (77e182036) adds four reads, and the existence check reads the gate twice.
        if($label===null){same([6, 2], [$queries, $gateQueries], 'Warm existence reads the cache fence and the current catalogue gate');}
        else{same([6, 2], [$queries, $gateQueries], $label.' warm existence reads the cache fence and the current catalogue gate');}
    }
}
$nativeSuccessful = true;
echo "Transfer existence display parity and early-return query bounds passed.\n";
