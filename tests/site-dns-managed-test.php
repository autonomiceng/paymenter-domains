<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProfile,ManagedPublicConfig};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,ManagedDnsSetup,ManagedPublicTarget,ManagedPublicReconciliation,ManagedPublicWorkSchema};
// Real holding/native-service fixture is required: enrollment checks immutable account and ownership evidence.
extract(managedFixture(hosting:false));$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now;
$default=DB::transaction(fn()=>WatchProfile::create(['name'=>'Deployment default','nameservers'=>['ns1.deployment.test','ns2.deployment.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'enabled'=>true,'edited_by'=>$staff->id,'reason'=>'Synthetic site policy']));
$manager=staffUser(['admin.domains.view',\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW,\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::MANAGE]);
SiteDnsDefault::save($manager,$default->id);
same(0,ManagedDnsSetup::affected($now)->count(),'All eligible holdings inherit the site default before reconciliation');
expect($signals->enqueue($id,$uid,$now),'Existing holding reconciles using the site reference');
same($default->id,ManagedDnsSetup::profile($d,ManagedPublicConfig::find($id))->id,'Managed target resolves site reference');
same(null,ManagedPublicConfig::find($id)->profile_id,'Reconciliation does not assign inherited profiles');
same(1,count((new ManagedPublicReconciliation($signals))->due($now)),'Unhosted holding schedules delegation only');
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
$nativeSuccessful=true;echo "Managed site DNS reference passed.\n";
