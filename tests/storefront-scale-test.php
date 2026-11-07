<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/OfferFixture.php';
// Native Eloquent product/settings/plan loading is the regression; pure rule tests cannot cover it.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
use App\Models\{Plan, Product};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, StorefrontController};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, SalesPolicy, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CheckoutEligibility, NativeCatalogue, PslReviewSchema, Storefront, WatchSchema};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

require __DIR__.'/Support/offer-commerce.php';
(new Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
extract(commerceFixture(true));
$names = [];
foreach (range('a', 'z') as $a) {
    foreach (range('a', 'z') as $b) {
        $names[] = 'scale'.$a.$b;
        if (count($names) === 599) { break 2; }
    }
}
$base = $tlds[0]->fresh(); $binding = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id', $base->server_id)->sole());
$templates = [];
foreach (['register', 'transfer'] as $action) {
    $product = $native->product($base, $action); $plan = $product->plans()->where('billing_period', 1)->sole();
    $templates[$action] = [$product, $product->settings()->get(), $plan, $plan->prices()->sole()];
}
$priceTemplates = Price::where('server_id', $base->server_id)->where('term', 1)->get();
// Read scale is the boundary. Clone the real Writer seed in bounded inserts rather than
// replaying thousands of identical Eloquent saves. The same 599 endings, native products,
// settings, plans, prices and seller identities remain in the measured database.
DB::transaction(function () use ($names, $base, $templates, $priceTemplates): void {
    $rows=['domains_tlds'=>[],'domains_prices'=>[],'products'=>[],'settings'=>[],'plans'=>[],'prices'=>[],'domains_sales_policies'=>[]];
    $tldId=(int)Tld::max('id');$productId=(int)Product::max('id');$planId=(int)Plan::max('id');
    $published=Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($base->catalogue_published_revision);$published['selection']=1;
    $intent=Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json(new Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell:true,longest:1,transfers:false));
    $policy=SalesPolicy::where('tld','com')->sole()->getRawOriginal();unset($policy['id']);
    foreach($names as $name){
        $tld=$base->replicate();$tld->id=++$tldId;$tld->tld=$name;
        $tld->catalogue_intent=$intent;$tld->catalogue_published_revision=json_encode($published);
        $rows['domains_tlds'][]=$tld->getAttributes();
        foreach($priceTemplates as $template){$price=$template->replicate();$price->tld=$name;$rows['domains_prices'][]=$price->getAttributes();}
        foreach($templates as $action=>[$template,$settings,$plan,$nativePrice]){
            $product=$template->replicate();$product->id=++$productId;$product->slug=NativeCatalogue::slug($tld,$action);$product->name=ucfirst($action).' .'.$name;
            $rows['products'][]=$product->getAttributes();
            foreach($settings as $templateSetting){
                $setting=$templateSetting->replicate();$setting->settingable_id=$product->id;
                if($setting->key==='domains_tld_id'){$setting->value=(string)$tld->id;}
                $rows['settings'][]=$setting->getAttributes();
            }
            $copy=$plan->replicate();$copy->id=++$planId;$copy->priceable_id=$product->id;$rows['plans'][]=$copy->getAttributes();
            $price=$nativePrice->replicate();$price->plan_id=$copy->id;$rows['prices'][]=$price->getAttributes();
        }
        $rows['domains_sales_policies'][]=[...$policy,'tld'=>$name,'revision'=>1,'register_enabled'=>true,'transfer_enabled'=>true];
    }
    foreach($rows as $table=>$batch){foreach(array_chunk($batch,100) as $chunk){DB::table($table)->insert($chunk);}}
});
// Discard only the seed catalogue in this disposable memory database, leaving the exact scale shape.
DB::table('domains_sales_policies')->where('tld', 'com')->delete();
DB::table('products')->whereNotIn('slug', array_merge(
    array_map(fn ($name) => 'domains-enom-s'.$base->server_id.'-'.$name.'-registration', $names),
    array_map(fn ($name) => 'domains-enom-s'.$base->server_id.'-'.$name.'-transfer', $names)))->delete();
DB::table('plans')->whereNotIn('priceable_id', Product::pluck('id'))->delete();
DB::table('prices')->whereNotIn('plan_id', Plan::pluck('id'))->delete();
DB::table('domains_prices')->where('tld', 'com')->delete(); DB::table('domains_tlds')->where('tld', 'com')->delete();
PslReviewSchema::install(); WatchSchema::install();
$clock = new FakeClock($now);
// Every ending above carries independently known synthetic facts/costs from the real Writer seed.
// Native products/plans are cloned explicitly to keep setup bounded at 599 endings.
$ports = new Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts(new Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),
    $clock, $bindings, static fn () => null, static fn () => true);
$offers = new Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers($ports, $bindings, $native);
$store = new class($sellers, $prices, $native, $bindings, activeFixtureEligibility($bindings, $clock), $clock) extends Storefront { use OfferCommerceRoute; };
$store->fixtureOffers = $offers;

app()->instance(Storefront::class, $store); app()->instance(Clock::class, $clock);
require dirname(__DIR__).'/Domains/routes/web.php'; require dirname(__DIR__).'/Domains/routes/owned.php';
storefrontViews(); $user = commerceUser(); Auth::login($user);
$controller = new StorefrontController($store); $owned = app(OwnedDomainController::class);
expect(!Illuminate\Support\Facades\Schema::hasTable('domains_sell_rows'),'Retired sell store remains absent at reseller scale');
same([599, 599, 1797, 1198, 1198], [Tld::count(), SalesPolicy::count(), Price::count(), Product::count(), Plan::count()], 'Reseller sells the full scale shape');
$failures = []; $before = $reads->calls; $count = 0; $measuring = false; $queries = [];
DB::listen(static function ($query) use (&$count, &$measuring, &$queries): void {
    if ($measuring) { expect(str_starts_with(strtolower($query->sql), 'select'), 'Page GET only reads our database'); $count++; $queries[] = $query->sql; }
});
foreach (['/domains', '/domains/register', '/domains/pricing'] as $url) {
    Cache::flush(); $request = Request::create($url);
    $request->setLaravelSession(app('session')->driver()); $request->setUserResolver(fn () => $user);
    $count = 0; $measuring = true;
    try {
        $view = match ($url) { '/domains' => $owned->index($request), '/domains/register' => $controller->search($request), default => $controller->pricing($request) };
        $html = $view->render();
    } finally { $measuring = false; }
    echo $url.' scale queries: '.$count."\n";
    if ($count > 64) { $failures[] = $url.': '.$count; }
    if ($url === '/domains/pricing') { same(599, storefrontDom($html)->query('//tbody[@id="domains-price-rows"]/tr')->length, 'All prices stay in server HTML without script'); }
    if ($url === '/domains') { same(false, $view->getData()['transfersOffered'], 'No transfer link after all TLD transfers are turned off'); }
    else {
        same(599, count($view->getData()[$url === '/domains/register' ? 'endings' : 'rows']), 'Every offered registration remains visible');
        expect(str_contains($html, 'scaleaa') && str_contains($html, 'scalexa'), 'Real Blade renders the first and last catalogue rows');
    }
}
same($before, $reads->calls, 'All three page GETs make zero registrar calls');
expect($failures === [], 'At most 64 queries per page at 599 endings: '.implode(', ', $failures));
$routed=$store;
$count = 0; $measuring = true;
try { $answer = $routed->check('example.scaleaa'); } finally { $measuring = false; }
echo 'Routed check queries: '.$count."\n";
expect($count <= 64, 'Routed check at 599 endings must use at most 64 queries; got '.$count);
same(['available', '$7.80'], [$answer['status'], $answer['price']], 'Routed search retains exact selected-account retail');
Cache::flush(); $before = $reads->calls; $count = 0; $measuring = true;
try { $rows = $routed->endings(); } finally { $measuring = false; }
echo 'Routed cold endings queries: '.$count."\n";
expect($count <= 64, 'Routed cold catalogue at 599 endings must use at most 64 queries; got '.$count);
same(599, count($rows), 'Routed batch renders every healthy ending');
$count = 0; $queries = []; $measuring = true;
try { same($rows, $routed->endings(), 'Warm routed cache retains the entire catalogue'); } finally { $measuring = false; }
echo 'Routed warm endings queries: '.$count."\n";
expect($count <= 8, 'A cache hit scans account state only, with at most eight queries; got '.$count);
expect(!preg_grep('/domains_tlds|domains_prices|products|plans/i', $queries), 'Warm hit must not fetch the offer/native catalogue');
same($before, $reads->calls, 'Cold and warm routed displays never resolve a provider');
// A corrupt row in a batch cannot withdraw healthy siblings, even on that same account.
DB::table('domains_tlds')->where('tld', 'scaleaa')->update(['catalogue_facts' => '{invalid']);
Cache::flush();
same(598, count($routed->endings()), 'Malformed normalized facts isolate exactly one ending');
$nativeSuccessful = true; echo "Reseller-scale customer page query bounds passed.\n";
