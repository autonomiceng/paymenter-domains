<?php
// Synthetic offline transfer observation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/observation-namesilo.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationCandidates,ObservationSchema,ObservationWork};
// A broken backlog is isolated and its account turn cannot monopolize the next worker.
for($i=0;$i<30;$i++){DB::table('domains_observation_work')->insert(['server_id'=>$enom,'account_fingerprint'=>Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$enom)->value('account_fingerprint'),
    'domain_id'=>900000+$i,'generation'=>1,'due_at'=>0]);}
$first=$work->tick(1);same([$enom],array_keys($first),'Broken account bounded to one turn');same('account_unavailable',$first[$enom]['holding'],'Broken holding backlog keeps its safe failure cause');
$calls=count($observe->calls);
$second=$work->tick(1);same([$ns],array_keys($second),'Next turn belongs to the other account');same('idle',$second[$ns]['holding'],'Transfer status is owned by financial reconciliation');
same(0,DB::table('domains_observation_work')->where('domain_id',$d->id)->count(),'No duplicate transfer observation work');
(observationFixtureScheduler($observer,$clock,activeFixturePorts($bindings)))->tick(2);
same($calls,count($observe->calls),'Restart does not add another transfer poller');
same($before,$original->fresh()->getAttributes(),'Observation scheduler does not change the original transfer');
// Optional manual observation still reads facts without completing the financial operation.
$observer->run($d->id);same($before['state'],$original->fresh()->state,'Manual pending read never completes transfer');
$observe->status='Transfer Completed';$clock->instant=$clock->now()->modify('+1 minute');$observer->run($d->id);
same($before,$original->fresh()->getAttributes(),'Even terminal/owned facts cannot complete transfer financially');same(null,$d->fresh()->renewal_policy['billing_anchor']??null,'No financial anchor from observer');
$nativeSuccessful=true;echo "NameSilo two-account pending scheduler fairness and no financial mutation passed.\n";
