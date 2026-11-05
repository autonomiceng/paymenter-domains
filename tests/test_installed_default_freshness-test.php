<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Reuse the existing native global-pricing fixture with verified read-only dependencies.
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferState;
require __DIR__.'/offer-global-pricing-test.php';
$nativeSuccessful=false;
// Publication prechecks use the account's current age policy, not the feed's original deadline.
foreach([$binding,$second] as $b){
    App\Models\Server::findOrFail($b->serverId)->settings()->updateOrCreate(['key'=>\Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness::KEY],['value'=>'72','type'=>'integer','encrypted'=>false]);
    foreach(DB::table('domains_prices')->where('server_id',$b->serverId)->get() as $p){
        $cost=OfferState::decode($p->catalogue_observation);$cost['source']='enom_bulk_bucket_'.((int)$p->term===3?2:1);
        $cost['observed_at']=$now->modify('-60 hours')->format(DATE_ATOM);$cost['fresh_until']=$now->modify('-12 hours')->format(DATE_ATOM);
        DB::table('domains_prices')->where('id',$p->id)->update(['catalogue_observation'=>OfferState::json($cost)]);
    }
}
$result=$save('41.00');same([],$result['failed'],'Global pricing honors the saved 72-hour policy');
same('$41.00',$storefront->offer('com','register',1)['price'],'Fresh-under-policy costs publish into native offers');
foreach([$binding,$second] as $b){App\Models\Server::findOrFail($b->serverId)->settings()->where('key',\Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness::KEY)->update(['value'=>'24']);}
$result=$save('42.00');expect(count($result['failed'])>=2,'Shorter account policy rejects the same old observations');
$nativeSuccessful=true;
echo "Default pricing account freshness: ok\n";
