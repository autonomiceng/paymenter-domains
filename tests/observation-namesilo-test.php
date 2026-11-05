<?php
// Synthetic offline transfer observation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationCandidates,ObservationSchema,ObservationWork};
ObservationSchema::install();$budget=new AccountReadBudget($clock);$observer=new DomainObservation($readBindings,$clock,$budget);
[$d,$driver,$original]=$holding('pending-window',false);$observe->status='Pending at Registry';$before=$original->getAttributes();
$work=new ObservationWork($observer,$clock,$budget);$enom=$tlds[0]->server_id;$ns=$d->server_id;$work->enroll($enom);$work->enroll($ns);
// A broken backlog is isolated and its account turn cannot monopolize the next worker.
for($i=0;$i<30;$i++){DB::table('domains_observation_work')->insert(['server_id'=>$enom,'account_fingerprint'=>Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$enom)->value('account_fingerprint'),
    'domain_id'=>900000+$i,'generation'=>1,'due_at'=>0]);}
same([$enom=> 'account_unavailable'],array_map(static fn($r)=>$r['holding'],$work->tick(1)),'Broken account bounded to one turn');
$calls=count($observe->calls);
same([$ns=>'idle'],array_map(static fn($r)=>$r['holding'],$work->tick(1)),'Transfer status is owned by financial reconciliation');
same(0,DB::table('domains_observation_work')->where('domain_id',$d->id)->count(),'No duplicate transfer observation work');
(new ObservationWork($observer,$clock,$budget))->tick(2);
same($calls,count($observe->calls),'Restart does not add another transfer poller');
same($before,$original->fresh()->getAttributes(),'Observation scheduler does not change the original transfer');
// Optional manual observation still reads facts without completing the financial operation.
$observer->run($d->id);same($before['state'],$original->fresh()->state,'Manual pending read never completes transfer');
$observe->status='Transfer Completed';$clock->instant=$clock->now()->modify('+1 minute');$observer->run($d->id);
same($before,$original->fresh()->getAttributes(),'Even terminal/owned facts cannot complete transfer financially');same(null,$d->fresh()->renewal_policy['billing_anchor']??null,'No financial anchor from observer');
// Separate candidate cursors are fair, persist across calls and grant no financial mutation.
same([$enom],array_keys(ObservationCandidates::next('refund',1)),'First candidate account');same([$ns],array_keys(ObservationCandidates::next('refund',1)),'Candidate fairness independent of observation backlog');
$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);$missing=$newTransfer(placeholderComFixtureName('missing-observation-code-placeholder.com'),1,true,false);$work->tick(2);
$bindings->setProcessing($ns,false);$selected=ObservationCandidates::next('timeout',2);expect(in_array($missing->id,$selected[$ns],true),'Missing-code candidate selected even without processing');
same('awaiting_code',$missing->fresh()->state,'Selection does not timeout or credit');
$bindings->setProcessing($ns, true, syntheticActivation());
// Resume advances the catalogue revision; publish locally before creating the follow-up transfer.
activeFixtureRepublish($bindings, Paymenter\Extensions\Servers\Domains\Models\Tld::where(['server_id'=>$ns,'tld'=>'com'])->sole());
[$active,$unused,$acquired]=$holding('followup');same('pending',$active->observation['followup']['state'],'Transfer completion seeds durable follow-up');
$bindings->setProcessing($ns,false);$clock->instant=$clock->now()->modify('+1 minute');same('refreshed',$observer->run($active->id),'Paused old account refreshes');
same(1,$active->fresh()->observation['followup']['reads'],'Follow-up advances facts only');
// Explicit verifier enrollment predates the local offer-enrollment marker.
same(false,App\Models\Server::findOrFail($ns)->settings()->where('key',Paymenter\Extensions\Servers\Domains\Services\LocalAccountEnrollment::KEY)->exists(),'Legacy NameSilo fixture has no local offer marker');
$offerRefused=false;
try{$readBindings->offerCredentialRevision(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$ns)->sole()));}catch(DomainException){$offerRefused=true;}
same(true,$offerRefused,'Offer feeds still require local NameSilo enrollment');
$scheduled=new ObservationWork($observer,$clock,$budget);
DB::table('domains_observation_work')->where('server_id',$ns)->delete();
$calls=count($observe->calls);
same('refreshed',$scheduled->tick(2)[$ns]['holding'],'Scheduled legacy NameSilo holding reads accept explicit verified enrollment');
expect(count($observe->calls)>$calls,'Scheduled NameSilo holding reaches its established synthetic reader');
// NS15 native encrypted round-trip, owner projection and sequence planning with opaque CAA/SRV siblings.
[$d,$driver,$original]=$holding('ns15');$ms->route='apibatch';
$dnsRows=[['record_id'=>'A1','type'=>'A','host'=>$d->domain,'value'=>'8.8.8.8','ttl'=>'300','distance'=>'0'],
 ['record_id'=>'CAA1','type'=>'CAA','host'=>$d->domain,'value'=>'0:issue:ca.example','ttl'=>'7207','distance'=>'0'],
 ['record_id'=>'SRV1','type'=>'SRV','host'=>'_sip._tcp.'.$d->domain,'value'=>'5:443:sip.example.','ttl'=>'600','distance'=>'7']];
$dnsFault=false;
$nsExchange=static function($origin,$cmd,$p,$timeout)use($managementExchange,&$dnsRows,&$dnsFault):string {
 if($cmd==='dnsListRecords'){return $dnsFault?'{}':json_encode(['request'=>['operation'=>$cmd],'reply'=>['code'=>300,'detail'=>'ok','resource_record'=>$dnsRows]]);}
 return $managementExchange($origin,$cmd,$p,$timeout);
};
$ns15=new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ObservationReader($binding,$nsExchange);
$ctx=fn()=>new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25),$clock,stopOnReadFailure:true);
$request=new \Paymenter\Extensions\Servers\Domains\Data\ManagementRequest($binding,'ns15-ns',$d->domain,'discovery',[],domainId:$d->id);
$ms->ids=array_fill_keys(['registrant','administrative','technical','billing'],'900001');
$ms->profiles['900001']=['first_name'=>'Synthetic','last_name'=>'Person','company'=>'','address'=>'1 Example Street','address2'=>'','city'=>'Portland','state'=>'OR','zip'=>'97201','country'=>'US','email'=>'synthetic@example.com','phone'=>'+1.5035550123'];
$discovered=$ns15->discover($request,$ctx());
same('complete',$discovered['dns']['state'],'NameSilo canonical DNS inventory complete');
same('complete',$discovered['settings']['contacts']['state'],'Bounded contacts read resolves all exact role IDs');
same(1,count(array_filter($ms->calls,fn($r)=>$r[0]==='contactList')),'Shared contact IDs consume one read');
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::save($d,$discovered,$clock->now(),$ns15->observationCapabilities());
$good=$d->fresh()->observation['records'];
same(false,\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::incomplete($d->fresh()),'DS mutation Off / unsupported read is not a perpetual failure');
$inventory=\Paymenter\Extensions\Servers\Domains\Services\OwnerRecords::nameSilo($d->fresh());
same($discovered['dns']['records'],$inventory['records'],'Encrypted CAA, TTL, provider IDs and opaque SRV survive projection');
$view=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsForm::view($inventory);
same(['CAA','SRV'],array_column($view['fixed'],'type'),'Unsupported edit types stay read-only');
$desired=$view['records'];$desired[0]['address']='1.1.1.1';
$payload=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsForm::payload($inventory,$desired,['0'],$view['digest']);
$steps=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsSequence::plan($d->domain,$inventory,$payload['records']);
same(1,count($steps),'Editing A leaves CAA/SRV siblings untouched');same(300,$steps[0]['after']['ttl'],'Existing TTL retained');
$clock->instant=$clock->now()->modify('+1 minute');$dnsRows[]=['record_id'=>'bad','type'=>'A','host'=>$d->domain,'value'=>'invalid','ttl'=>'300','distance'=>'0'];
$partial=$ns15->discover($request,$ctx());
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::save($d->fresh(),$partial,$clock->now(),$ns15->observationCapabilities());
same('partial',$d->fresh()->observation['records']['families']['dns']['state'],'Malformed sibling withdraws whole-list completeness');
same(3,count($d->fresh()->observation['records']['families']['dns']['records']),'Valid partial siblings retained');
same(null,\Paymenter\Extensions\Servers\Domains\Services\OwnerRecords::nameSilo($d->fresh()),'Partial DNS cannot authorize whole-list editing');
$status=\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::recordStatus($d->fresh(),$clock->now()->modify('+1 day'));
same('unsupported',$status['families']['ds']['expectation'],'DS read and mutation support remain separate');
same('unknown',$status['families']['ds']['state'],'Unsupported DS is never fabricated complete empty');
same(true,$status['stale'],'Daily stale state is explicit');same($good['at'],$status['families']['dns']['last_good_at'],'Last-good DNS time retained');
$before=$d->fresh()->observation;$dnsFault=true;
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::failPrimary($d->fresh(),$clock->now());
same($before['records'],$d->fresh()->observation['records'],'Failed primary preserves exact prior encrypted inventory/time');

$nativeSuccessful=true;echo "NameSilo fair pending window and durable follow-up passed\n";
