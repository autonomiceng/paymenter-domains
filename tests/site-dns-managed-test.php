<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProfile,ManagedPublicConfig};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,ManagedDnsSetup,ManagedPublicTarget,ManagedPublicReconciliation,ManagedPublicWorkSchema,PublicDnsQuota};
// Real holding/native-service fixture is required: enrollment checks immutable account and ownership evidence.
extract(managedFixture(hosting:false));$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now;
$default=DB::transaction(fn()=>WatchProfile::create(['name'=>'Deployment default','nameservers'=>['ns1.deployment.test','ns2.deployment.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'enabled'=>true,'edited_by'=>$staff->id,'reason'=>'Synthetic site policy']));
$manager=staffUser(['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.new_domain_dns',\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW,\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::MANAGE]);
// A site default change still discovers an eligible holding that has no config row yet.
DB::table('domains_managed_public_config')->where('domain_id',$id)->delete();
$pool=fn()=>array_map('intval',array_values((array)DB::table(PublicDnsQuota::TABLE)->where('id',1)->select('sweep_due_at','sweep_cursor')->first()));
PublicDnsQuota::seed($now);DB::table(PublicDnsQuota::TABLE)->where('id',1)->update(['sweep_due_at'=>$now+86400,'sweep_cursor'=>$id]);
SiteDnsDefault::save($manager,$default->id);
same([0,0],$pool(),'A site default change sweeps an eligible holding without a config row');
same(0,ManagedDnsSetup::affected($now)->count(),'All eligible holdings inherit the site default before reconciliation');
expect($signals->enqueue($id,$uid,$now),'Existing holding reconciles using the site reference');
same($default->id,ManagedDnsSetup::profile($d,ManagedPublicConfig::find($id))->id,'Managed target resolves site reference');
same(null,ManagedPublicConfig::find($id)->profile_id,'Reconciliation does not assign inherited profiles');
same(1,count((new ManagedPublicReconciliation($signals))->due($now)),'Unhosted holding schedules delegation only');
// A new template revision is new work: the old revision's retry delay is cleared, unrelated reads keep theirs.
$retry=fn()=>array_map('intval',array_values((array)DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('kind','delegation')->select('attempts','backoff_until','due_at')->first()));
DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('kind','delegation')->update(['attempts'=>3,'backoff_until'=>$now+3600,'due_at'=>$now+3600]);
expect($signals->enqueue($id,$uid,$now),'Unchanged template reconciles');same([3,$now+3600,$now+3600],$retry(),'An unchanged template keeps its retry delay');
DB::transaction(fn()=>$default->update(['revision'=>$default->revision+1,'nameservers'=>['ns1.edited.test','ns2.edited.test'],'edited_by'=>$staff->id,'reason'=>'Synthetic edited policy']));
expect($signals->enqueue($id,$uid,$now),'Edited template reconciles');same([0,0,$now],$retry(),'A new template revision clears the old retry delay');
$before=$d->fresh()->getRawOriginal();$providerCalls=count($wire->calls);
$replacement=DB::transaction(fn()=>WatchProfile::create(['name'=>'Other default','nameservers'=>['ns1.other.test','ns2.other.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'enabled'=>true,'edited_by'=>$staff->id,'reason'=>'Synthetic changed policy']));
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new \Paymenter\Extensions\Servers\Domains\Services\WatchWork($fake,new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(fn()=>0),true,fn()=>$now));
$ticket=$signals->begin($id,$uid,'delegation',$now);expect($ticket!==null,'Inherited comparison can be claimed');
SiteDnsDefault::save($manager,$replacement->id);
expect(!$signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','platform'),$now+1),'Changed site default rejects stale managed answer');
same($replacement->id,ManagedDnsSetup::profile($d,ManagedPublicConfig::find($id))->id,'Existing domain follows current site reference');
DB::transaction(function()use($id,$default,$staff,$now){ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$default,'external',$staff->id,'staff',$now);});
SiteDnsDefault::save($manager,$replacement->id);
same($default->id,ManagedDnsSetup::profile($d,ManagedPublicConfig::find($id))->id,'Explicit domain template survives site change');
same('external',ManagedPublicConfig::find($id)->expected_dns,'Explicit domain expectation survives');
same($before,$d->fresh()->getRawOriginal(),'Reconciliation never changes holding authority or registrar nameservers');
same($providerCalls,count($wire->calls),'Reconciliation sends zero registrar requests');
app(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class)->boot();
DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->delete();
$d->fresh()->update(['status'=>'grace']);
same(1,DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->count(),'Holding attachment event enrolls local DNS work before daily sweep');
// Expired-only inventory: a site-wide refresh starts no managed sweep.
DB::table('domains_domains')->where('id',$id)->update(['status'=>'expired']);
DB::table(PublicDnsQuota::TABLE)->where('id',1)->update(['sweep_due_at'=>$now+86400,'sweep_cursor'=>$id]);
SiteDnsDefault::reconcile();same([$now+86400,$id],$pool(),'An expired-only inventory starts no managed sweep');
$nativeSuccessful=true;echo "Managed site DNS reference passed.\n";
