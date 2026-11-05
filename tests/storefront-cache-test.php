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
$warm=static function(string $label)use($storefront):array{
    DB::enableQueryLog();DB::flushQueryLog();try{$rows=$storefront->endings();$queries=DB::getQueryLog();}finally{DB::disableQueryLog();}
    foreach($queries as $q){expect(!preg_match('/\b(domains_tlds|domains_prices|products|plans|prices)\b/i',$q['query']),$label.' avoids per-offer/native catalogue reads');}
    return $rows;
};
same($healthy,$warm('Warm account fence'),'Warm display retains current active rows');
$t=$tlds[0]->fresh();
DB::beginTransaction();$t->update(['catalogue_revision'=>(int)$t->catalogue_revision+1]);DB::beginTransaction();$t->update(['catalogue_revision'=>(int)$t->catalogue_revision+1]);DB::commit();DB::rollBack();
same($healthy,$warm('Rolled-back outer mutation'),'Rolled-back outer mutation preserves warm display');
DB::beginTransaction();DB::beginTransaction();$t->refresh()->update(['catalogue_revision'=>(int)$t->catalogue_revision+1]);DB::rollBack();DB::commit();
same($healthy,$warm('Rolled-back savepoint'),'Rolled-back savepoint cannot invalidate committed display');
DB::transaction(fn()=>$t->refresh()->update(['catalogue_revision'=>(int)$t->catalogue_revision+1]));
same([],$storefront->endings(),'Committed pending offer revision withdraws cached public rows');
$q=new Query($binding,['com'],'register');$ports->apply(Edit::republish($binding,'com'),offerCommerceAsStaff(static fn () => $ports->readOffers($q,Context::Staff)[0]->revision));
same($healthy,$storefront->endings(),'Real active publication restores next display');
// An unrevisioned native edit is display-only until TTL; it cannot change authoritative cart pricing.
$product=$native->product($t->fresh(),'register');$price=$product->plans()->where('billing_period',1)->sole()->prices()->sole();
$raw=$price->getRawOriginal('price');DB::table('prices')->where('id',$price->id)->update(['price'=>'99.00']);
$clock->instant=$now->modify('+29 seconds');same($healthy,$storefront->endings(),'Native edit may lag for less than thirty seconds');
$clock->instant=$now->modify('+30 seconds');$expired=$storefront->endings();same([3],$expired[0]['terms'],'Exact TTL expiry removes only the corrupt native term');same(null,$expired[0]['register'],'Corrupt annual mapping has no public annual price');same(null,$expired[0]['price'],'Annual display does not invent a price from a longer term');
DB::table('prices')->where('id',$price->id)->update(['price'=>$raw]);$clock->instant=$now->modify('+60 seconds');
same($healthy,$storefront->endings(),'Next display rechecks repaired native price');
$root=Cache::getFacadeRoot();$committed=false;Cache::swap(new class(new Illuminate\Cache\ArrayStore) extends Illuminate\Cache\Repository {
    public function forever($key,$value){throw new RuntimeException('Synthetic cache outage');}
});
try{DB::transaction(function()use(&$committed){\Paymenter\Extensions\Servers\Domains\Services\StorefrontCatalogueCache::invalidateAfterCommit();DB::afterCommit(static function()use(&$committed){$committed=true;});});}finally{Cache::swap($root);}
same(true,$committed,'Display invalidation outage cannot suppress other committed callbacks');
same(0,App\Models\Invoice::count(),'Display cache never creates debt');same(0,App\Models\Service::count(),'Display cache never creates services');
$nativeSuccessful=true;echo "Active storefront cache: ok\n";
