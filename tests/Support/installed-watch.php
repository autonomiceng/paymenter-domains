<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. ZIP-resolved retained watch/adoption rehearsal.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProfile,WatchProductMap,WatchName,WatchSource,WatchRegistryObservation,WatchNotice,Adoption};
use Paymenter\Extensions\Servers\Domains\Services\{WatchWork,WatchDiscoveryWork,WatchNoticePreferences};
function assertInstalledWatchGates(): void
{
    foreach(['domains.adoption.enabled','domains.watch_transfer.enabled']as$key){same(false,config($key,false),'Installation never enables '.$key);}
    same(0,WatchProfile::where('enabled',true)->count(),'No active public-check profile');same(0,WatchProductMap::where('enabled',true)->count(),'No discovery activation');
    same(false,app(WatchWork::class)->active(),'Installed public workers remain off');same(false,app(WatchDiscoveryWork::class)->ready(),'Installed discovery remains off');same('disabled',app(WatchWork::class)->status()['reason'],'Installed worker gate is disabled');
    if(WatchName::count()){ $watch=WatchName::sole();same(false,WatchNoticePreferences::read($watch->user_id,$watch->canonical_name)['target']['enabled'],'Retained effective opt-out');same(false,WatchNoticePreferences::enabled($watch->user_id,$watch->canonical_name),'Notices remain opted out');same('synthetic-notice',WatchNotice::sole()->data['marker'],'Encrypted notice remains readable');same('retained-episode',json_decode(Illuminate\Support\Facades\Crypt::decryptString(DB::table('domains_watch_notice_state')->sole()->data),true)['marker'],'Encrypted episode remains readable'); }
    if(Adoption::count()){same('preview',Adoption::sole()->state,'Retained preview grants no holding');same(null,Adoption::sole()->domain_id,'No adoption attachment');same('synthetic-retained',Adoption::sole()->evidence['marker'],'Encrypted preview remains readable');same('synthetic-registry',WatchRegistryObservation::sole()->facts['marker'],'Encrypted registry remains readable');}
}
function seedInstalledWatch(): void
{
    require_once __DIR__.'/commerce.php';require_once __DIR__.'/native-commerce.php';
    $owner=App\Models\User::firstOrFail();$account=Paymenter\Extensions\Servers\Domains\Models\Account::firstOrFail();
    $server=App\Models\Server::create(['name'=>'Hosting','extension'=>'SyntheticHosting','type'=>'server','enabled'=>true]);
    $product=commerceHosting();$product->update(['server_id'=>$server->id]);$plan=$product->plans()->sole();$order=App\Models\Order::withoutEvents(fn()=>App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']));
    $service=App\Models\Service::withoutEvents(fn()=>App\Models\Service::create(['user_id'=>$owner->id,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'quantity'=>1,'status'=>'active','price'=>'5.00','currency_code'=>'USD']));$service->properties()->create(['key'=>'domain','value'=>'www.external.example.com']);
    DB::transaction(function()use($owner,$product,$service,$account):void {
        $profile=WatchProfile::create(['name'=>'Retained defaults','nameservers'=>['ns.example.test'],'website_endpoints'=>['ips'=>['192.0.2.1']],'email_endpoints'=>['exact'=>['mx.example.test']],'edited_by'=>$owner->id,'reason'=>'Synthetic retained profile']);
        $map=WatchProductMap::create(['product_id'=>$product->id,'property_key'=>'domain','edited_by'=>$owner->id,'reason'=>'Synthetic retained mapping']);
        $watch=WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'example.com','state'=>'paused','registration'=>'external','psl_revision'=>str_repeat('a',64),'ns_profile_id'=>$profile->id,'profile_revision'=>1,'notice_preferences'=>['expiry'=>false]]);
        WatchSource::create(['user_id'=>$owner->id,'service_id'=>$service->id,'map_id'=>$map->id,'map_revision'=>1,'hostname'=>'www.external.example.com','registrable_name'=>'example.com','watch_id'=>$watch->id,'state'=>'watch','source_digest'=>str_repeat('b',64),'last_seen_at'=>1800000000]);
        WatchRegistryObservation::create(['watch_id'=>$watch->id,'user_id'=>$owner->id,'stamp'=>str_repeat('c',64),'bootstrap_revision'=>str_repeat('d',64),'started_at'=>1800000000,'completed_at'=>1800000001,'result'=>'unknown','reason'=>'no_expiry','safe_code'=>'expiry_unknown','facts'=>['marker'=>'synthetic-registry']]);
        Adoption::create(['request_key'=>'retained-preview','request_digest'=>str_repeat('e',64),'service_id'=>$service->id,'user_id'=>$owner->id,'server_id'=>$account->server_id,'registrar_key'=>$account->registrar_key,'environment'=>$account->environment,'account_fingerprint'=>$account->account_fingerprint,'state'=>'preview','origin'=>'staff','evidence'=>['version'=>1,'registrar_key'=>$account->registrar_key,'environment'=>$account->environment,'marker'=>'synthetic-retained'],'actor_id'=>$owner->id,'reason'=>'Synthetic retained preview','do_not_renew'=>true,'observed_at'=>'2026-10-20 00:00:00','valid_until'=>'2026-10-20 00:02:00']);
        DB::table('domains_watch_work')->insert(['watch_id'=>$watch->id,'kind'=>'expiry','watch_revision'=>1,'due_at'=>1800000000,'claim'=>str_repeat('f',64),'lease_until'=>1800000120]);
        $scope=WatchNoticePreferences::scope((int)$owner->id,$watch->canonical_name);
        DB::table('domains_watch_notice_state')->insert(['scope'=>$scope,'user_id'=>$owner->id,'data'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode(['marker'=>'retained-episode'])),'updated_at'=>1800000000]);
        $n=WatchNotice::create(['notice_key'=>str_repeat('b',64),'scope'=>$scope,'target_kind'=>'watch','watch_id'=>$watch->id,'user_id'=>$owner->id,'kind'=>'expiring','episode_key'=>str_repeat('c',64),'family'=>'registry','observation_id'=>WatchRegistryObservation::sole()->id,'threshold'=>7,'state'=>'open','first_seen_at'=>1800000000,'last_seen_at'=>1800000000,'data'=>['marker'=>'synthetic-notice','domain'=>$watch->canonical_name,'date'=>'2027-01-22','episode'=>'synthetic-expiry','lineage'=>'','priority'=>1,'channels'=>['mail','app']]])->id;
        DB::table('domains_watch_deliveries')->insert(['notice_id'=>$n,'user_id'=>$owner->id,'channel'=>'mail','delivery_key'=>str_repeat('d',64),'template_key'=>'domains_watch_expiring','state'=>'uncertain','reserved_at'=>1800000000]);
        DB::table('domains_watch_preferences')->insert(['scope'=>$scope,'user_id'=>$owner->id,'enabled'=>false]);
    });
    assertInstalledWatchGates();
}
