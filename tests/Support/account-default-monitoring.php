<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Account defaults, retained customer choices and no-zone monitoring share real holding locks and DNS reads.
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{NewDomainDns,ManagedPublicTarget,ManagedDnsSetup,WatchNoticeTarget,ManagedPublicReconciliation,ManagedPublicWorkSchema};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,ManagedPublicConfig,ManagedPublicObservation};
$account=Account::where('server_id',$d->server_id)->sole();
$default=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create(['name'=>'Account monitoring default',
    'enabled'=>true,'nameservers'=>['ns1.default.example','ns2.default.example'],'website_endpoints'=>['ips'=>['192.0.2.10']],
    'email_endpoints'=>['exact'=>['mx.default.example']],'edited_by'=>$staff->id,'reason'=>'Synthetic account default']));
DB::transaction(fn()=>NewDomainDns::saveLocked($account,$default->id));
same(0,ManagedDnsSetup::affected($now)->count(),'An existing domain picks up its account default before any manual enrollment');
same(1,\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts::usedBy($default->id),'Template usage includes inherited managed domains');
DB::table('domains_managed_public_config')->where('domain_id',$id)->delete();
same(0,ManagedDnsSetup::affected($now)->count(),'A newly enrolled domain inherits the same default without a config row');
$providerCalls=count($wire->calls);$authority=$d->fresh()->getRawOriginal();
DB::table(\Paymenter\Extensions\Servers\Domains\Services\PublicDnsQuota::TABLE)->update(['sweep_due_at'=>$now,'sweep_cursor'=>0]);
$sweep=new ManagedPublicReconciliation($signals);$sweep->sweep($now);
$target=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$now));
same((int)$default->id,(int)$target['profile']->id,'Normal daily sweep uses the registrar account template');
same(null,ManagedPublicConfig::find($id)->profile_id,'Account inheritance does not freeze a per-domain override');
$defaultSummary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$now);
expect(!in_array('domain.dns.not_configured',$defaultSummary->stateKeys,true),'Customer setup notice agrees with account inheritance');
same(['delegation'],array_column($sweep->due($now),'kind'),'No hosting means nameserver checks only, even with template record rules');
$fake->answers['example.com|NS']=managedNs('ns1.customer.example');
expect($signals->observe($id,$uid,'delegation'),'Domain without a zone has a successful nameserver check');
expect(!$signals->observe($id,$uid,'points'),'No zone never runs record checks');
same(['example.com|NS'],$fake->calls,'No MX, A, AAAA or zone lookup for a domain without hosting');
same(0,ManagedPublicObservation::where('check_kind','points')->count(),'Missing zone produces no failed record observation');
same(0,(int)DB::table(ManagedPublicWorkSchema::TABLE)->max('attempts'),'No-zone checks do not count as resolver failures');
expect(!(new \Paymenter\Extensions\Servers\Domains\Services\WatchMaintenance())->health($now)['outage'],'A missing zone never raises a resolver alarm');
// Use real retained operation rows, so transfer/customer priority is checked through target capture and notice eligibility.
$op=Operation::create(['domain_id'=>$id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,
    'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>'monitoring-customer-choice',
    'action'=>'register','state'=>'succeeded','currency'=>'USD','payload'=>['nameservers'=>['ns1.customer.example','ns2.customer.example']]]);
foreach(['register','transfer']as$action){
    DB::table('domains_operations')->where('id',$op->id)->update(['action'=>$action]);
    managedDue($id,$now);$ticket=$signals->begin($id,$uid,'delegation',$now);expect($ticket!==null,'Chosen DNS remains monitored: '.$action);
    expect($signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external'),$now+1),'External DNS result retained: '.$action);
    $notice=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$now+1));
    same(false,$notice['expected'],'Customer nameservers and transfers are never expected to match the default: '.$action);
}
DB::table('domains_operations')->where('id',$op->id)->delete();
// Confirm both external samples before checking portal and delivery policy for current customer intent.
$intentResults=[];
foreach(['later customer change','adopted customer nameservers','return from inherited external']as$case){
    $registration=null;$change=null;$leave=null;$local=$d->fresh();$savedConfig=ManagedPublicConfig::find($id)->getRawOriginal();
    $returned=$case==='return from inherited external';
    $customerNs=['ns1.customer.example','ns2.customer.example'];
    if($case!=='adopted customer nameservers'){
        if($returned){
            DB::transaction(fn()=>ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$default,'external',null,'predecessor_watch',$now));
            $customerNs=$default->profile()->nameservers;
        }
        $registration=Operation::create(['domain_id'=>$id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,
            'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>'monitoring-original-default',
            'action'=>$returned?'transfer':'register','state'=>'succeeded','currency'=>'USD','payload'=>['nameservers'=>$default->profile()->nameservers],
            'created_at'=>gmdate('Y-m-d H:i:s',$now-60),'completed_at'=>gmdate('Y-m-d H:i:s',$now-60)]);
        $read=['sequence'=>1,'operation_key'=>'monitoring-later-choice','generation'=>$d->authority_generation,'action'=>'nameservers',
            'at'=>gmdate(DATE_ATOM,$now),'confirmed'=>true,'facts'=>['nameservers'=>$customerNs]];
        $change=Operation::create(['domain_id'=>$id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,
            'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>$read['operation_key'],
            'action'=>'nameservers','state'=>'succeeded','currency'=>'USD','payload'=>['nameservers'=>$customerNs],'policy'=>['management'=>['section'=>'nameservers']],
            'attempt'=>['seal'=>'sealed','management_reads'=>[$read]],'completed_at'=>gmdate('Y-m-d H:i:s',$now)]);
        expect(\Paymenter\Extensions\Servers\Domains\Services\ManagementEvidence::resolved($change,$local),'Later customer choice has successful retained readback');
        $local->observation=[...($local->observation??[]),'sections'=>['nameservers'=>\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::section($local,$read)]];
    }else{
        $local->observation=\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::recordNameservers($local,
            \Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence::observed($customerNs,'namesilo_domain_info',new DateTimeImmutable('@'.$now)));
    }
    DB::table('domains_domains')->where('id',$id)->update(['observation'=>$local->getAttributes()['observation']]);
    DB::table('domains_managed_public_observations')->where('domain_id',$id)->delete();
    $returnExpected=null;
    $samples=$returned?[[$now,'platform'],[$now+901,'platform'],[$now+1802,'external'],[$now+2703,'external']]:[[$now,'external'],[$now+901,'external']];
    foreach($samples as[$sampleAt,$observedDns]){
        managedDue($id,$sampleAt);$ticket=$signals->begin($id,$uid,'delegation',$sampleAt);expect($ticket!==null,'Customer DNS remains monitored: '.$case);
        expect($signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation',$observedDns),$sampleAt+1),'Customer DNS sample retained: '.$case);
        if($returned && $sampleAt===$now+901){$returnExpected=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$sampleAt+1))['expected'];}
    }
    // The successful ledger choice must also survive an absent observation projection.
    if($change){DB::table('domains_domains')->where('id',$id)->update(['observation'=>$authority['observation']]);}
    $checkedAt=$sampleAt+1;
    $notice=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$checkedAt));
    same(true,$notice['delegation']['ns_confirmed']??null,'Two matching external samples are confirmed: '.$case);
    $plan=\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePlan::dns([],$notice['delegation'],$checkedAt,$notice['expected']);
    $summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$checkedAt);
    $intentResults[$case]=['expected'=>$notice['expected'],'notices'=>array_column($plan['notices'],'kind'),
        'portal'=>$plan['portal'],'summary'=>array_values(array_intersect($summary->stateKeys,['watch.ns_pending','watch.ns_changed']))];
    if($returned){
        $intentResults[$case]['return_expected']=$returnExpected;
        DB::transaction(fn()=>ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$default,'external',(int)$staff->id,'staff',$checkedAt));
        $intentResults[$case]['staff_external']=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$checkedAt))['expected'];
        // The mirror transition is an authorized leave after returning; retain the earlier return too.
        $read=['sequence'=>2,'operation_key'=>'monitoring-leave-again','generation'=>$d->authority_generation,'action'=>'nameservers',
            'at'=>gmdate(DATE_ATOM,$checkedAt+1),'confirmed'=>true,'facts'=>['nameservers'=>['ns1.customer.example','ns2.customer.example']]];
        $leave=Operation::create([...$change->only(['domain_id','service_id','server_id','registrar_key','account_fingerprint','authority_generation','action','currency','policy']),
            'operation_key'=>$read['operation_key'],'state'=>'succeeded','payload'=>$read['facts'],'attempt'=>['seal'=>'sealed','management_reads'=>[$read]],'completed_at'=>gmdate('Y-m-d H:i:s',$checkedAt+1)]);
        DB::transaction(fn()=>ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$default,'platform',null,'predecessor_watch',$checkedAt+1));
        foreach([$checkedAt+1,$checkedAt+902]as$sampleAt){
            managedDue($id,$sampleAt);$ticket=$signals->begin($id,$uid,'delegation',$sampleAt);expect($ticket!==null,'Authorized second leave remains monitored');
            expect($signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external'),$sampleAt+1),'Authorized second leave sample retained');
        }
        $mirror=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$sampleAt+1));
        same(true,$mirror['delegation']['ns_confirmed']??null,'Second leave is confirmed');
        $mirrorPlan=\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePlan::dns($plan['state'],$mirror['delegation'],$sampleAt+1,$mirror['expected']);
        $mirrorSummary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$sampleAt+1);
        $intentResults[$case]['leave_again']=[$mirror['expected'],array_column($mirrorPlan['notices'],'kind'),$mirrorPlan['portal'],
            array_values(array_intersect($mirrorSummary->stateKeys,['watch.ns_pending','watch.ns_changed']))];
        DB::transaction(fn()=>ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$default,'platform',(int)$staff->id,'staff',$sampleAt+2));
        $intentResults[$case]['staff_platform']=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$sampleAt+2))['expected'];
    }
    if($leave){DB::table('domains_operations')->where('id',$leave->id)->delete();}
    if($change){DB::table('domains_operations')->where('id',$change->id)->delete();}
    if($registration){DB::table('domains_operations')->where('id',$registration->id)->delete();}
    DB::table('domains_domains')->where('id',$id)->update(['observation'=>$authority['observation']]);
    DB::table('domains_managed_public_config')->where('domain_id',$id)->update($savedConfig);
}
$quietIntent=['expected'=>false,'notices'=>[],'portal'=>[],'summary'=>[]];
same(['later customer change'=>$quietIntent,'adopted customer nameservers'=>$quietIntent,'return from inherited external'=>[
    'expected'=>true,'notices'=>['ns_changed'],'portal'=>['ns_changed'],'summary'=>['watch.ns_pending'],'return_expected'=>true,
    'staff_external'=>false,'leave_again'=>[false,[],[],[]],'staff_platform'=>true]],$intentResults,'Nameserver intent follows the current choice and deliberate staff assignment: '.json_encode($intentResults));
// A concurrent default change discards the old answer and the next check uses the new template.
managedDue($id,$now);$ticket=$signals->begin($id,$uid,'delegation',$now);expect($ticket!==null,'Default read captured');
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['nameservers'=>json_encode(['ns1.override.example','ns2.override.example'])]);
DB::transaction(fn()=>NewDomainDns::saveLocked($account,$profile->id));
expect(!$signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','platform'),$now+1),'Changed default invalidates in-flight comparison');
$target=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$now));same((int)$profile->id,(int)$target['profile']->id,'Existing null config follows changed account default');
DB::transaction(function()use($id,$default){$c=ManagedPublicConfig::find($id);$c->fill(['profile_id'=>$default->id,'expected_dns'=>'external','revision'=>$c->revision+1])->save();});
$target=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$now));same((int)$default->id,(int)$target['profile']->id,'Staff per-domain assignment wins over account default');
same('external',$target['config']->expected_dns,'Staff intent wins too');
DB::table('domains_watch_profiles')->where('id',$default->id)->update(['enabled'=>false]);
same(0,ManagedDnsSetup::affected($now)->count(),'Unusable explicit comparison falls back to a usable account default without a staff step');
$target=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$now));same((int)$profile->id,(int)$target['profile']->id,'Unavailable override uses the account fallback');
same($authority,$d->fresh()->getRawOriginal(),'Automatic monitoring never changes nameservers or holding authority');same($providerCalls,count($wire->calls),'Automatic monitoring makes zero registrar calls');
// Restore the unconfigured fixture used by the setup and recovery tests below.
DB::transaction(fn()=>NewDomainDns::saveLocked($account,null));
DB::transaction(function()use($id){$c=ManagedPublicConfig::find($id);$c->fill(['profile_id'=>null,'expected_dns'=>null,'revision'=>$c->revision+1])->save();});
DB::table('domains_watch_profiles')->where('id',$default->id)->delete();
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['nameservers'=>json_encode(['ns.platform.test'])]);
DB::table(ManagedPublicWorkSchema::TABLE)->delete();DB::table('domains_managed_public_observations')->delete();$fake->calls=[];
