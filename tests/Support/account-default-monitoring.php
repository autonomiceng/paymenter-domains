<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Site defaults, retained customer choices and no-zone monitoring share real holding locks and DNS reads.

require_once __DIR__.'/site-dns-default.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{NewDomainDns,ManagedPublicTarget,ManagedDnsSetup,WatchNoticeTarget,ManagedPublicReconciliation,ManagedPublicWorkSchema};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,ManagedPublicConfig,ManagedPublicObservation};
$account=Account::where('server_id',$d->server_id)->sole();
$default=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create(['name'=>'Account monitoring default',
    'enabled'=>true,'nameservers'=>['ns1.default.example','ns2.default.example'],'website_endpoints'=>['ips'=>['192.0.2.10']],
    'email_endpoints'=>['exact'=>['mx.default.example']],'edited_by'=>$staff->id,'reason'=>'Synthetic site default']));
siteDnsFixture($default->id);
same(0,ManagedDnsSetup::affected($now)->count(),'An existing domain picks up its site default before any manual enrollment');
same(1,\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts::usedBy($default->id),'Template usage includes inherited managed domains');
DB::table('domains_managed_public_config')->where('domain_id',$id)->delete();
same(0,ManagedDnsSetup::affected($now)->count(),'A newly enrolled domain inherits the same default without a config row');
$providerCalls=count($wire->calls);$authority=$d->fresh()->getRawOriginal();
DB::table(\Paymenter\Extensions\Servers\Domains\Services\PublicDnsQuota::TABLE)->update(['sweep_due_at'=>$now,'sweep_cursor'=>0]);
$sweep=new ManagedPublicReconciliation($signals);$sweep->sweep($now);
$target=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$now));
same((int)$default->id,(int)$target['profile']->id,'Normal daily sweep uses the registrar site template');
same(null,ManagedPublicConfig::find($id)->profile_id,'Site inheritance does not freeze a per-domain override');
$defaultSummary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$now);
expect(!in_array('domain.dns.not_configured',$defaultSummary->stateKeys,true),'Customer setup notice agrees with site inheritance');
same(['delegation'],array_column($sweep->due($now),'kind'),'No hosting means nameserver checks only, even with template record rules');
$fake->answers['example.com|NS']=managedNs('ns1.customer.example');
expect($signals->observe($id,$uid,'delegation'),'Domain without a zone has a successful nameserver check');
expect(!$signals->observe($id,$uid,'points'),'No zone never runs record checks');
same(['example.com|NS'],$fake->calls,'No MX, A, AAAA or zone lookup for a domain without hosting');
same(0,ManagedPublicObservation::where('check_kind','points')->count(),'Missing zone produces no failed record observation');
same(0,(int)DB::table(ManagedPublicWorkSchema::TABLE)->max('attempts'),'No-zone checks do not count as resolver failures');
expect(!(new \Paymenter\Extensions\Servers\Domains\Services\WatchMaintenance())->health($now)['outage'],'A missing zone never raises a resolver alarm');
DB::table('domains_domains')->where('id',$id)->update(['observation'=>$authority['observation']]);
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
$refreshNameservers=static function($domain,array $actual,array $chosen,int $at)use($account,$clock):void{
    $binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);$calls=[];
    $port=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($binding,static function($origin,$command,$parameters,$timeout)use(&$calls,$actual,$domain):string{
        $calls[]=$command;
        $body=match($command){
            'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="'.$domain->provider_domain_id.'">'.$domain->domain.'</DomainName><Status><Belongs-To>account-enom</Belongs-To></Status></GetDomainInfo>',
            'GetDNS'=>implode('',array_map(static fn($ns)=>'<dns>'.$ns.'</dns>',$actual)),
        };
        return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
    });
    $request=new \Paymenter\Extensions\Servers\Domains\Data\ManagementRequest($binding,'observation:'.$domain->id,$domain->domain,'nameservers',['nameservers'=>$chosen],$domain->provider_domain_id);
    $context=new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(2),$clock);
    $observed=$port->observe($request,$context);
    same(['GetDomainInfo','GetDNS'],$calls,'Refresh reads the changed registrar state without a write or a nameserver request');
    $projection=$domain->observation??[];
    $projection['sections']['nameservers']=\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::section($domain,['operation_key'=>$request->key,'at'=>gmdate(DATE_ATOM,$at),'facts'=>$observed->facts()]);
    $domain->observation=$projection;
    DB::table('domains_domains')->where('id',$domain->id)->update(['observation'=>$domain->getAttributes()['observation']]);
};
$intentResults=[];
foreach(['later customer change','adopted customer nameservers','legacy without baseline','platform import without baseline','platform first public read','return from inherited external','return then unchosen refresh','pending chosen change']as$case){
    $registration=null;$change=null;$leave=null;$local=$d->fresh();$savedConfig=ManagedPublicConfig::find($id)->getRawOriginal();
    if($case==='pending chosen change'){
        // SQLite has no repeatable-read views: model the old operations snapshot only for nonlocking reads.
        // A request becomes visible after finish's seed read and before its first holding-prefix lock.
        $readView=(object)['phase'=>null,'late'=>null,'create'=>null,'current'=>false];
        Operation::addGlobalScope('monitoring_finish_read_view',static function($query)use($readView):void{
            if($readView->phase===null || $readView->late===null){return;}
            if($query->getQuery()->lock){$readView->current=true;return;}
            $query->where('domains_operations.id','<>',$readView->late);
        });
        DB::listen(static function($query)use($readView):void{
            if($readView->phase==='seed' && str_starts_with($query->sql,'select') && str_contains($query->sql,'from "domains_domains"')){$readView->phase='waiting';}
        });
        DB::connection()->beforeExecuting(static function($sql)use($readView):void{
            if($readView->phase==='waiting' && str_contains($sql,'from "users"')){
                $readView->phase='current';$readView->late=($readView->create)()->id;
            }
        });
        $pendingResults=[];
        foreach(['queued','pending','running','uncertain']as$state){
            foreach(['enrollment','before read','during read',...($state==='uncertain'?['lock wait']:[])]as$when){
                DB::transaction(fn()=>ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$default,'platform',null,'hosting_product',$now));
                $chosen=['ns1.customer.example','ns2.customer.example'];
                $request=static fn()=>Operation::create(['domain_id'=>$id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,
                    'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>'monitoring-pending-choice',
                    'action'=>'nameservers','state'=>$state,'currency'=>'USD','payload'=>['nameservers'=>$chosen],'policy'=>['management'=>['section'=>'nameservers']],
                    'attempt'=>$state==='queued'?[]:['seal'=>'sealed'],'authorized_by'=>$uid]);
                $pending=in_array($when,['during read','lock wait'],true)?null:$request();
                if($when==='enrollment'){$refreshNameservers($d->fresh(),$default->profile()->nameservers,$chosen,$now);}
                expect($signals->enqueue($id,$uid,$now),'Imported holding with in-flight choice is enrolled: '.$state.' / '.$when);
                managedDue($id,$now);$ticket=$signals->begin($id,$uid,'delegation',$now);expect($ticket!==null,'Platform read starts beside chosen request');
                if($when==='during read'){$pending=$request();}
                $answer=\Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::rrset(new \Paymenter\Extensions\Servers\Domains\Dns\DnsQuery('example.com','NS'),
                    array_map(static fn($host)=>['host'=>'example.com','class'=>'IN','type'=>'NS','target'=>$host,'ttl'=>300],$default->profile()->nameservers));
                if($when==='lock wait'){$readView->phase='seed';$readView->create=$request;}
                try{expect($signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','platform',queries:[$answer]),$now+1),'Platform NS read is accepted, including a choice created after begin');}
                finally{$readView->phase=null;}
                if($when==='lock wait'){
                    expect($readView->late!==null && $readView->current,'Late request is present in the current holding-lock read after the old read view starts');
                    $pending=Operation::findOrFail($readView->late);
                }
                $refreshNameservers($d->fresh(),$chosen,$chosen,$now+2);
                expect($signals->enqueue($id,$uid,$now+2),'Unresolved chosen departure passes through enrollment again');
                $answer=\Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::rrset(new \Paymenter\Extensions\Servers\Domains\Dns\DnsQuery('example.com','NS'),
                    array_map(static fn($host)=>['host'=>'example.com','class'=>'IN','type'=>'NS','target'=>$host,'ttl'=>300],$chosen));
                foreach([$now+10,$now+911]as$at){
                    managedDue($id,$at);$ticket=$signals->begin($id,$uid,'delegation',$at);expect($ticket!==null,'Requested external change stays monitored');
                    expect($signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external',queries:[$answer]),$at+1),'Requested external NS read is accepted');
                }
                $notice=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$at+1));
                same(true,$notice['delegation']['ns_confirmed']??null,'Requested external delegation is confirmed');
                $plan=\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePlan::dns([],$notice['delegation'],$at+1,$notice['expected']);
                $summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$at+1);
                $pendingResults[$state.' / '.$when]=[\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::registrationNameservers($d->fresh())===null,
                    $notice['expected'],array_column($plan['notices'],'kind'),$plan['portal'],array_values(array_intersect($summary->stateKeys,['watch.ns_pending','watch.ns_changed']))];
                DB::table('domains_operations')->where('id',$pending->id)->delete();
                DB::table('domains_domains')->where('id',$id)->update(['observation'=>$authority['observation']]);
                DB::table('domains_managed_public_config')->where('domain_id',$id)->update($savedConfig);
            }
        }
        $intentResults[$case]=array_values($pendingResults);
        expect(count(array_filter($pendingResults,static fn($result)=>$result!==[true,false,[],[],[]]))===0,'Pending chosen request never acquires read-derived intent: '.json_encode($pendingResults));
        continue;
    }
    $publicFirst=$case==='platform first public read';$platformImport=$case==='platform import without baseline'||$publicFirst;$legacy=$case==='legacy without baseline'||$platformImport;$refresh=$case==='return then unchosen refresh';$returned=$case==='return from inherited external'||$refresh;
    $customerNs=['ns1.customer.example','ns2.customer.example'];
    if($case!=='adopted customer nameservers' && !$legacy){
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
    }elseif(!$legacy){
        $local->observation=\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::recordNameservers($local,
            \Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence::observed($customerNs,'namesilo_domain_info',new DateTimeImmutable('@'.$now)));
    }
    DB::table('domains_domains')->where('id',$id)->update(['observation'=>$local->getAttributes()['observation']]);
    if($legacy){
        DB::transaction(fn()=>ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$default,'platform',null,'hosting_product',$now));
        $initialNs=$platformImport?$default->profile()->nameservers:$customerNs;
        if(!$publicFirst){$refreshNameservers($local->fresh(),$initialNs,$initialNs,$now);}
        same(null,\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::registrationNameservers($local->fresh()),'Legacy import has no manufactured acquisition baseline');
        same(0,Operation::where('domain_id',$id)->where('state','succeeded')->count(),'Legacy import has no successful choice');
        expect($signals->enqueue($id,$uid,$now),'Normal enrollment retains the first usable import read without a provider call');
        if($publicFirst){
            $fake->answers['example.com|NS']=\Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::rrset(new \Paymenter\Extensions\Servers\Domains\Dns\DnsQuery('example.com','NS'),
                array_map(static fn($host)=>['host'=>'example.com','class'=>'IN','type'=>'NS','target'=>$host,'ttl'=>300],$initialNs));
            managedDue($id,$now);expect($signals->observe($id,$uid,'delegation'),'An import with no stored section uses its first ordinary public DNS read');
        }
        $upgradeIntent=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$now))['config']->expected_dns;
    }
    if($platformImport){$refreshNameservers($local->fresh(),$customerNs,$customerNs,$now+1);expect($signals->enqueue($id,$uid,$now+1),'Later enrollment must not replace the platform baseline');}
    if($case==='adopted customer nameservers'){$refreshNameservers($local->fresh(),$default->profile()->nameservers,$customerNs,$now+1);}
    DB::table('domains_managed_public_observations')->where('domain_id',$id)->delete();
    $returnExpected=null;
    $samples=$returned?[[$now,'platform'],[$now+901,'platform'],[$now+1802,'external'],[$now+2703,'external']]:[[$now,'external'],[$now+901,'external']];
    foreach($samples as[$sampleAt,$observedDns]){
        // A chosen, confirmed return is followed by an out-of-band departure, then the ordinary nameserver read.
        if($refresh && $sampleAt===$now+1802){$refreshNameservers($local->fresh(),['ns1.customer.example','ns2.customer.example'],$customerNs,$sampleAt);}
        managedDue($id,$sampleAt);$ticket=$signals->begin($id,$uid,'delegation',$sampleAt);expect($ticket!==null,'Customer DNS remains monitored: '.$case);
        expect($signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation',$observedDns),$sampleAt+1),'Customer DNS sample retained: '.$case);
        if($returned && $sampleAt===$now+901){$returnExpected=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$sampleAt+1))['expected'];}
    }
    // The successful ledger choice must also survive an absent observation projection.
    if($change && !$refresh){DB::table('domains_domains')->where('id',$id)->update(['observation'=>$authority['observation']]);}
    $checkedAt=$sampleAt+1;
    $notice=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$checkedAt));
    same(true,$notice['delegation']['ns_confirmed']??null,'Two matching external samples are confirmed: '.$case);
    $plan=\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePlan::dns([],$notice['delegation'],$checkedAt,$notice['expected']);
    $summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$checkedAt);
    $intentResults[$case]=['expected'=>$notice['expected'],'notices'=>array_column($plan['notices'],'kind'),
        'portal'=>$plan['portal'],'summary'=>array_values(array_intersect($summary->stateKeys,['watch.ns_pending','watch.ns_changed']))];
    if($legacy){
        $intentResults[$case]['upgrade_intent']=$upgradeIntent;$intentResults[$case]['later_reads']=[];
        foreach([$default->profile()->nameservers,$customerNs]as$index=>$actual){
            $at=$checkedAt+$index+1;$refreshNameservers($local->fresh(),$actual,$customerNs,$at);
            expect($signals->enqueue($id,$uid,$at),'Later reads pass through ordinary enrollment');
            $later=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$at));
            $laterPlan=\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePlan::dns($plan['state'],$later['delegation'],$at,$later['expected']);
            $laterSummary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$at);
            $intentResults[$case]['later_reads'][]=[$later['expected'],array_column($laterPlan['notices'],'kind'),$laterPlan['portal'],array_values(array_intersect($laterSummary->stateKeys,['watch.ns_pending','watch.ns_changed']))];
        }
        DB::transaction(fn()=>ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$default,'platform',(int)$staff->id,'staff',$at));
        $intentResults[$case]['staff_platform']=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$at))['expected'];
    }
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
        if($refresh){$refreshNameservers($local->fresh(),$default->profile()->nameservers,$read['facts']['nameservers'],$checkedAt+2);}
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
$platformImportIntent=['expected'=>true,'notices'=>['ns_changed'],'portal'=>['ns_changed'],'summary'=>['watch.ns_pending'],'upgrade_intent'=>'platform',
    'later_reads'=>[[true,[],['ns_changed'],['watch.ns_pending']],[true,[],['ns_changed'],['watch.ns_pending']]],'staff_platform'=>true];
$returnIntent=[
    'expected'=>true,'notices'=>['ns_changed'],'portal'=>['ns_changed'],'summary'=>['watch.ns_pending'],'return_expected'=>true,
    'staff_external'=>false,'leave_again'=>[false,[],[],[]],'staff_platform'=>true];
same(['later customer change'=>$quietIntent,'adopted customer nameservers'=>$quietIntent,'legacy without baseline'=>[...$quietIntent,'upgrade_intent'=>'external','later_reads'=>[[false,[],[],[]],[false,[],[],[]]],'staff_platform'=>true],
    'platform import without baseline'=>$platformImportIntent,'platform first public read'=>$platformImportIntent,
    'return from inherited external'=>$returnIntent,'return then unchosen refresh'=>$returnIntent,'pending chosen change'=>array_fill(0,13,[true,false,[],[],[]])],$intentResults,'Nameserver intent follows the current choice and deliberate staff assignment: '.json_encode($intentResults));
// A newer unresolved success cannot hide an earlier confirmed customer choice.
$local=$d->fresh();$savedConfig=ManagedPublicConfig::find($id)->getRawOriginal();
DB::transaction(fn()=>ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$default,'platform',null,'hosting_product',$now));
$choiceBase=['domain_id'=>$id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,
    'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'action'=>'nameservers','state'=>'succeeded','currency'=>'USD',
    'policy'=>['management'=>['section'=>'nameservers']],'completed_at'=>gmdate('Y-m-d H:i:s',$now)];
$read=['sequence'=>1,'operation_key'=>'older-resolved-choice','generation'=>$d->authority_generation,'action'=>'nameservers','at'=>gmdate(DATE_ATOM,$now),'confirmed'=>true,
    'facts'=>['nameservers'=>['ns1.customer.example','ns2.customer.example']]];
$original=Operation::create([...$choiceBase,'action'=>'register','operation_key'=>'original-platform-registration',
    'payload'=>['nameservers'=>$default->profile()->nameservers],'completed_at'=>gmdate('Y-m-d H:i:s',$now-60)]);
$resolved=Operation::create([...$choiceBase,'operation_key'=>$read['operation_key'],'payload'=>$read['facts'],'attempt'=>['seal'=>'sealed','management_reads'=>[$read]]]);
$unresolved=Operation::create([...$choiceBase,'operation_key'=>'newer-unresolved-choice','payload'=>['nameservers'=>$default->profile()->nameservers],'attempt'=>['seal'=>'sealed']]);
// Retained unresolved history can push the real choice beyond the first bounded read page.
$overflow=[];$retained=$unresolved->getRawOriginal();unset($retained['id']);
for($i=0;$i<101;$i++){$overflow[]=DB::table('domains_operations')->insertGetId([...$retained,'operation_key'=>'unresolved-page-'.$i]);}
$wrong=Operation::create([...$choiceBase,'operation_key'=>'wrong-generation-choice','payload'=>['nameservers'=>$default->profile()->nameservers],
    'attempt'=>['seal'=>'sealed','management_reads'=>[[...$read,'operation_key'=>'wrong-generation-choice','generation'=>$d->authority_generation+1,'facts'=>['nameservers'=>$default->profile()->nameservers]]]]]);
DB::table('domains_operations')->where('id',$wrong->id)->update(['authority_generation'=>$d->authority_generation+1]);
$choiceResults=[];
foreach(['absent','stale','unreadable newer']as$evidence){
    if($evidence==='stale'){$refreshNameservers($local->fresh(),$default->profile()->nameservers,$default->profile()->nameservers,$now-90000);}
    if($evidence==='unreadable newer'){DB::table('domains_operations')->where('id',$unresolved->id)->update(['attempt'=>'unreadable']);}
    $sampleBase=$now+1000+count($choiceResults)*2000;
    foreach([$sampleBase,$sampleBase+901]as$sampleAt){
        managedDue($id,$sampleAt);$ticket=$signals->begin($id,$uid,'delegation',$sampleAt);expect($ticket!==null,'Retained choice permits ordinary monitoring');
        expect($signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external'),$sampleAt+1),'Current external sample follows the retained choice');
    }
    $notice=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$sampleAt+1));
    $summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$sampleAt+1);
    $choiceResults[$evidence]=[$notice['expected'],array_values(array_intersect($summary->stateKeys,['watch.ns_pending','watch.ns_changed']))];
}
same(array_fill_keys(['absent','stale','unreadable newer'],[false,[]]),$choiceResults,'Newest resolved choice survives unresolved, unreadable and wrong-identity successors: '.json_encode($choiceResults));
DB::table('domains_operations')->whereIn('id',[$original->id,$resolved->id,$unresolved->id,$wrong->id,...$overflow])->delete();
DB::table('domains_domains')->where('id',$id)->update(['observation'=>$authority['observation']]);
DB::table('domains_managed_public_config')->where('domain_id',$id)->update($savedConfig);
// Checkout inheritance follows a new account comparison; explicit and legacy customer choices keep theirs.
$savedConfig=ManagedPublicConfig::find($id)->getRawOriginal();
$newDefault=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create(['name'=>'Replacement monitoring default','enabled'=>true,
    'nameservers'=>['ns1.replacement.example','ns2.replacement.example'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$staff->id,'reason'=>'Synthetic changed default']));
siteDnsFixture($newDefault->id);
$originResults=[];
foreach(['inherited default','explicit equal default','legacy unmarked','domain override','staff assignment']as$case){
    DB::table('domains_managed_public_config')->where('domain_id',$id)->update([...$savedConfig,'profile_id'=>$case==='domain override'?$profile->id:null,
        'expected_dns'=>$case==='staff assignment'?'external':'platform','edited_by'=>$case==='staff assignment'?$staff->id:null]);
    $payload=['nameservers'=>$default->profile()->nameservers];
    if($case!=='legacy unmarked'){$payload['nameservers_origin']=$case==='explicit equal default'?['source'=>'customer']:
        ['source'=>'site_default','profile_id'=>(int)$default->id,'profile_revision'=>(int)$default->fresh()->revision];}
    $registration=Operation::create(['domain_id'=>$id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,
        'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'action'=>'register','state'=>'succeeded','currency'=>'USD',
        'operation_key'=>'provenance-'.str_replace(' ','-',$case),'payload'=>$payload,'completed_at'=>gmdate('Y-m-d H:i:s',$now-60)]);
    DB::table('domains_managed_public_observations')->where('domain_id',$id)->delete();
    $base=$now+10000+count($originResults)*2000;
    foreach([$base,$base+901]as$sampleAt){
        managedDue($id,$sampleAt);$ticket=$signals->begin($id,$uid,'delegation',$sampleAt);expect($ticket!==null,'Provenance remains monitored: '.$case);
        expect($signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external'),$sampleAt+1),'Old registration DNS differs from the effective comparison: '.$case);
    }
    $notice=DB::transaction(fn()=>WatchNoticeTarget::capture('managed',$id,$uid,$sampleAt+1));
    $target=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$sampleAt+1));
    $summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$sampleAt+1);
    $plan=\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePlan::dns([],$notice['delegation'],$sampleAt+1,$notice['expected']);
    $originResults[$case]=[$target['config']->expected_dns,$notice['expected'],array_column($plan['notices'],'kind'),$plan['portal'],
        array_values(array_intersect($summary->stateKeys,['watch.ns_pending','watch.ns_changed']))];
    DB::table('domains_operations')->where('id',$registration->id)->delete();
}
$inheritedOrigin=['platform',true,['ns_changed'],['ns_changed'],['watch.ns_pending']];$explicitOrigin=['external',false,[],[],[]];
same(['inherited default'=>$inheritedOrigin,'explicit equal default'=>$explicitOrigin,'legacy unmarked'=>$explicitOrigin,'domain override'=>$inheritedOrigin,'staff assignment'=>$explicitOrigin],
    $originResults,'Only inherited registrations follow the new comparison: '.json_encode($originResults));
siteDnsFixture($default->id);
DB::table('domains_watch_profiles')->where('id',$newDefault->id)->delete();
DB::table('domains_managed_public_config')->where('domain_id',$id)->update($savedConfig);
// A concurrent default change discards the old answer and the next check uses the new template.
managedDue($id,$now);$ticket=$signals->begin($id,$uid,'delegation',$now);expect($ticket!==null,'Default read captured');
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['nameservers'=>json_encode(['ns1.override.example','ns2.override.example'])]);
siteDnsFixture($profile->id);
expect(!$signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','platform'),$now+1),'Changed default invalidates in-flight comparison');
$target=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$now));same((int)$profile->id,(int)$target['profile']->id,'Existing null config follows changed site default');
DB::transaction(function()use($id,$default){$c=ManagedPublicConfig::find($id);$c->fill(['profile_id'=>$default->id,'expected_dns'=>'external','revision'=>$c->revision+1])->save();});
$target=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$now));same((int)$default->id,(int)$target['profile']->id,'Staff per-domain assignment wins over site default');
same('external',$target['config']->expected_dns,'Staff intent wins too');
DB::table('domains_watch_profiles')->where('id',$default->id)->update(['enabled'=>false]);
same(0,ManagedDnsSetup::affected($now)->count(),'Unusable explicit comparison falls back to a usable site default without a staff step');
$target=DB::transaction(fn()=>(new ManagedPublicTarget())->capture($id,$uid,$now));same((int)$profile->id,(int)$target['profile']->id,'Unavailable override uses the account fallback');
same($authority,$d->fresh()->getRawOriginal(),'Automatic monitoring never changes nameservers or holding authority');same($providerCalls,count($wire->calls),'Automatic monitoring makes zero registrar calls');
// Restore the unconfigured fixture used by the setup and recovery tests below.
siteDnsFixture(null);
DB::transaction(function()use($id){$c=ManagedPublicConfig::find($id);$c->fill(['profile_id'=>null,'expected_dns'=>null,'revision'=>$c->revision+1])->save();});
DB::table('domains_watch_profiles')->where('id',$default->id)->delete();
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['nameservers'=>json_encode(['ns.platform.test'])]);
DB::table(ManagedPublicWorkSchema::TABLE)->delete();DB::table('domains_managed_public_observations')->delete();$fake->calls=[];
