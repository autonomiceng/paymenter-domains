<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Query};
extract(offerCommerceFixture());
Cache::flush();$healthy=$storefront->endings();same(['com'],array_column($healthy,'tld'),'Known active offer renders before cache tests');
DB::enableQueryLog();DB::flushQueryLog();same($healthy,$storefront->endings(),'Warm display retains current active rows');
$queries=DB::getQueryLog();DB::disableQueryLog();
foreach($queries as $q){expect(!preg_match('/\b(domains_tlds|domains_prices|products|plans|prices)\b/i',$q['query']),'Warm account fence avoids per-offer/native catalogue reads');}
$t=$tlds[0]->fresh();
DB::beginTransaction();$t->increment('catalogue_revision');DB::beginTransaction();$t->increment('catalogue_revision');DB::commit();DB::rollBack();
same($healthy,$storefront->endings(),'Rolled-back outer mutation preserves warm display');
DB::beginTransaction();DB::beginTransaction();$t->refresh()->increment('catalogue_revision');DB::rollBack();DB::commit();
same($healthy,$storefront->endings(),'Rolled-back savepoint cannot invalidate committed display');
DB::transaction(fn()=>$t->refresh()->increment('catalogue_revision'));
same([],$storefront->endings(),'Committed pending offer revision withdraws cached public rows');
$q=new Query($binding,['com'],'register');$ports->apply(Edit::republish($binding,'com'),$ports->readOffers($q,Context::Staff)[0]->revision);
same($healthy,$storefront->endings(),'Real active publication restores next display');
// An unrevisioned native edit is display-only until TTL; it cannot change authoritative cart pricing.
$product=$native->product($t->fresh(),'register');$price=$product->plans()->where('billing_period',1)->sole()->prices()->sole();
$raw=$price->getRawOriginal('price');DB::table('prices')->where('id',$price->id)->update(['price'=>'99.00']);
$clock->instant=$now->modify('+29 seconds');same($healthy,$storefront->endings(),'Native edit may lag for less than thirty seconds');
$clock->instant=$now->modify('+30 seconds');same([],$storefront->endings(),'Exact TTL expiry rechecks corrupt native mapping');
DB::table('prices')->where('id',$price->id)->update(['price'=>$raw]);$clock->instant=$now->modify('+60 seconds');
same($healthy,$storefront->endings(),'Next display rechecks repaired native price');
$root=Cache::getFacadeRoot();Cache::swap(new class(new Illuminate\Cache\ArrayStore) extends Illuminate\Cache\Repository {
    public function get($key,$default=null){throw new RuntimeException('Synthetic cache outage');}
});
try{same($healthy,$storefront->endings(),'Cache outage falls back to current authoritative display');}finally{Cache::swap($root);}
same(0,App\Models\Invoice::count(),'Display cache never creates debt');same(0,App\Models\Service::count(),'Display cache never creates services');
$nativeSuccessful=true;echo "Active storefront cache: ok\n";
