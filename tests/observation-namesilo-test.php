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
same([$enom=> 'account_unavailable'],$work->tick(1),'Broken account bounded to one turn');
$calls=count($observe->calls);
same([$ns=>'idle'],$work->tick(1),'Transfer status is owned by financial reconciliation');
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
$bindings->setProcessing($ns, true, syntheticActivation());[$active,$unused,$acquired]=$holding('followup');same('pending',$active->observation['followup']['state'],'Transfer completion seeds durable follow-up');
$bindings->setProcessing($ns,false);$clock->instant=$clock->now()->modify('+1 minute');same('refreshed',$observer->run($active->id),'Paused old account refreshes');
same(1,$active->fresh()->observation['followup']['reads'],'Follow-up advances facts only');
$nativeSuccessful=true;echo "NameSilo fair pending window and durable follow-up passed\n";
