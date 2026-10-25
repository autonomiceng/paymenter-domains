<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Local predecessor for a proven transfer.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchSource,WatchProductMap,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\ManagedPublicEnrollment;
function transferWatchFixture(string $name,int $owner): array
{
    foreach(['ReminderSchema','PslSchema','PslReviewSchema','WatchSchema','WatchDnsSchema','WatchScheduleSchema','WatchNoticeSchema','HoldingAdoptionSchema','AdoptionSchema','HistoryLinkSchema','NativeIntentSchema','ManagedPublicWorkSchema','ManagedPublicSchema']as$type){('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$type)::install();}
    $server=App\Models\Server::create(['name'=>'Hosting','type'=>'server','extension'=>'SyntheticHosting','enabled'=>true]);
    $product=commerceHosting();$product->update(['server_id'=>$server->id]);
    $order=App\Models\Order::withoutEvents(fn()=>App\Models\Order::create(['user_id'=>$owner,'currency_code'=>'USD']));$plan=$product->plans()->sole();
    $service=App\Models\Service::withoutEvents(fn()=>App\Models\Service::create(['user_id'=>$owner,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']));
    $service->properties()->create(['key'=>'domain','value'=>'www.'.$name]);
    $map=DB::transaction(fn()=>WatchProductMap::create(['product_id'=>$product->id,'property_key'=>'domain','edited_by'=>$owner,'reason'=>'Synthetic transfer predecessor']));DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true]);
    $watch=DB::transaction(fn()=>WatchName::create(['user_id'=>$owner,'canonical_name'=>$name,'state'=>'active','registration'=>'external','psl_revision'=>str_repeat('a',64),'notice_preferences'=>['expiry'=>true]]));
    $source=DB::transaction(fn()=>WatchSource::create(['user_id'=>$owner,'service_id'=>$service->id,'map_id'=>$map->id,'map_revision'=>1,'hostname'=>'www.'.$name,'registrable_name'=>$name,'watch_id'=>$watch->id,'state'=>'watch','source_digest'=>str_repeat('b',64),'last_seen_at'=>1]));
    DB::table('domains_watch_work')->insert(['watch_id'=>$watch->id,'kind'=>'expiry','watch_revision'=>1,'due_at'=>1]);
    // Retained payloads are synthetic; the relink only changes definitely unsent lifecycle fields.
    $notice=DB::table('domains_watch_notices')->insertGetId(['user_id'=>$owner,'scope'=>str_repeat('a',64),'watch_id'=>$watch->id,'kind'=>'expiry','episode_key'=>str_repeat('b',64),'notice_key'=>str_repeat('c',64),'target_kind'=>'watch','family'=>'registry','observation_id'=>1,'state'=>'open','first_seen_at'=>1,'last_seen_at'=>1,'data'=>'synthetic']);
    foreach(['reserved','render_failed','accepted','uncertain']as$i=>$state){DB::table('domains_watch_deliveries')->insert(['notice_id'=>$notice,'user_id'=>$owner,'channel'=>'email','delivery_key'=>hash('sha256','transfer-delivery-'.$i),'template_key'=>'domains_watch_expiry','state'=>$state,'reserved_at'=>1]);}
    $failure=(object)['enabled'=>true];WatchAudit::creating(static function($a)use($failure){if($failure->enabled&&$a->event==='registration_managed'){throw new DomainException('Synthetic relink rollback');}});
    return compact('watch','source','notice','failure');
}
function assertTransferWatch(array $f,$op,int $now): void
{
    extract($f);same('succeeded',$op->state,'Relink failure never rewrites confirmed acquisition');same('active',$watch->fresh()->state,'Audit failure rolls whole relink back');same('watch',$source->fresh()->state,'No partial source change');same('open',DB::table('domains_watch_notices')->where('id',$notice)->value('state'),'No partial notice suppression');
    same(1,DB::table('domains_obligation_alerts')->where('code','transfer_watch_relink')->whereNull('resolved_at')->count(),'Deferred relink is staff visible');
    $failure->enabled=false;
    $product=App\Models\Service::findOrFail($op->domain->service_id)->product;
    foreach(['domains_action','domains_tld_id']as$key) {
        $marker=$product->settings()->where('key',$key)->sole()->getRawOriginal();
        DB::table('settings')->where('id',$marker['id'])->delete();
        try {
            same(null,(new ManagedPublicEnrollment)->reconcile((int)$op->domain_id,(int)$op->domain->user_id,$now,transferOnly:true),'Unavailable native marker defers transfer relink');
            same(false,(new Paymenter\Extensions\Servers\Domains\Services\TransferWatchEnrollment)->repair((int)$op->domain_id,(int)$op->domain->user_id,$now),'Unavailable native marker retains repair');
            same(1,DB::table('domains_obligation_alerts')->where('code','transfer_watch_relink')->whereNull('resolved_at')->count(),'Unavailable marker retains staff finding');
            same('active',$watch->fresh()->state,'Unavailable marker leaves predecessor watch active');
        } finally { DB::table('settings')->insert($marker); }
    }
    DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['environment'=>'test']);
    same(false,(new Paymenter\Extensions\Servers\Domains\Services\TransferWatchEnrollment)->repair((int)$op->domain_id,(int)$op->domain->user_id,$now),'TEST never changes an external watch');same('active',$watch->fresh()->state,'TEST retains watch notices');
    same(null,(new ManagedPublicEnrollment)->reconcile((int)$op->domain_id,(int)$op->domain->user_id,$now,transferOnly:true),'Locked TEST guard refuses relink');
    DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['environment'=>'live']);
    global $clock,$bindings,$prices,$native,$sellers;
    foreach(['RuntimeSchema','ObservationSchema','BalanceSchema','StaffWatchSchema']as$type){('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$type)::install();}
    app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
    foreach(['AccountBindings'=>$bindings,'PriceCatalogue'=>$prices,'NativeCatalogue'=>$native,'SellerPolicies'=>$sellers] as $type=>$value){app()->instance('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$type,$value);}
    Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
    (new Paymenter\Extensions\Servers\Domains\Services\RuntimeWork($clock,new Paymenter\Extensions\Servers\Domains\Services\RuntimePorts))->tick();
    same(0,DB::table('domains_obligation_alerts')->where('code','transfer_watch_relink')->whereNull('resolved_at')->count(),'Runtime repair clears staff finding');
    same('linked',$watch->fresh()->state,'Confirmed predecessor linked');same('retired',$source->fresh()->state,'Source history retained');same(1,WatchSource::where('managed_domain_id',$op->domain_id)->where('state','managed_link')->count(),'Hosting source points at acquired registration');
    same('suppressed',DB::table('domains_watch_notices')->where('id',$notice)->value('state'),'External notice stopped atomically');same(['suppressed','suppressed','accepted','uncertain'],DB::table('domains_watch_deliveries')->where('notice_id',$notice)->orderBy('id')->pluck('state')->all(),'Only unsent deliveries suppressed');same('registration_managed',DB::table('domains_watch_work')->where('watch_id',$watch->id)->value('safe_code'),'Claim cannot restart');
    same(0,DB::table('domains_managed_public_work')->count(),'Transfer relink activates no public reads');same(false,(new ManagedPublicEnrollment)->reconcile((int)$op->domain_id,(int)$op->domain->user_id,$now,transferOnly:true),'Repair idempotent');
    Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($op->domain,'transfer_watch_relink',$now);
    same(false,(new Paymenter\Extensions\Servers\Domains\Services\TransferWatchEnrollment)->repair((int)$op->domain_id,(int)$op->domain->user_id,$now),'Completed watch needs no repeat repair');
    same(0,DB::table('domains_obligation_alerts')->where('code','transfer_watch_relink')->whereNull('resolved_at')->count(),'No pending watch clears a stale relink finding');
}
