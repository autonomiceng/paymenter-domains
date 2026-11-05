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
$nativeSuccessful=true;echo "Managed site DNS reference passed.\n";
