<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native transfer with synthetic provider transports only.
declare(strict_types=1);
$fixtureTransferRegistrars=['enom'];
require __DIR__.'/Support/transfer-completion-enom-fixture.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ReadDeferred,TransferPolling};
use Paymenter\Extensions\Servers\Domains\Holding\Observation\ObservationRecords;
$op=$prepare(placeholderComFixtureName('pending-observation-placeholder.com'));$d=$op->domain()->firstOrFail();
$observe->fault='pending';$observe->unowned=true;$before=$d->fresh()->getRawOriginal();$writes=$state->calls;
$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock));
$calls=$observe->calls;
try { $observer->run($d->id); expect(false,'Holding refresh must defer a pending transfer'); } catch (ReadDeferred) {}
same($calls,$observe->calls,'Holding refresh spends no pending transfer read');
$r=$reconcile->run($op->id);
same('transfer_pending',$r->policy['reconciliation']['reason'],'Purchase polling records a healthy pending transfer');
expect(TransferPolling::expected($r),'Pending status remains on the purchase poll schedule');
$fresh=$d->fresh();same('transfer_pending',$fresh->status,'A pending poll cannot activate the transfer');
same($before,$fresh->getRawOriginal(),'Pending polling leaves holding facts and confirmed expiry alone');
expect(!isset($fresh->observation['record_failure'])&&!ObservationRecords::held($fresh),'Pending ownership creates no permanent record hold');
same($writes,$state->calls,'Polling cannot resend the transfer');
// A stopped or leased pending-phase row cannot suppress the new acquisition phase.
$oldKey=['domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>'holding'];
Illuminate\Support\Facades\DB::table('domains_observation_work')->updateOrInsert($oldKey,['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'due_at'=>0,'safe_code'=>'domain_not_in_account','claim'=>'synthetic-pending-claim','lease_until'=>$clock->now()->getTimestamp()+300]);
$old=(array)Illuminate\Support\Facades\DB::table('domains_observation_work')->where($oldKey)->sole();
$clock->instant=new DateTimeImmutable('@'.TransferPolling::schedule($r,$clock->now()->getTimestamp())['next_read_at']);
$observe->unowned=false;$observe->fault='';same('succeeded',$reconcile->run($op->id)->state,'Original confirmed transfer still completes normally');
$initial=Illuminate\Support\Facades\DB::table('domains_observation_work')->where('domain_id',$d->id)->where('phase',hash('sha256',$op->operation_key))->sole();
same('initial_read',$initial->safe_code,'Activation queues fresh initial work in the acquisition phase, marked as a first read');
same($old,(array)Illuminate\Support\Facades\DB::table('domains_observation_work')->where($oldKey)->sole(),'Existing pending work and its live lease are retained');
$observe->unowned=true;
same('domain_not_in_account',$observer->run($d->id),'The same unowned snapshot still stops an active domain');
same('domain_not_in_account',$d->fresh()->observation['record_failure']['code'],'Active ownership failure retains its exact cause');
expect(ObservationRecords::held($d->fresh()),'Active ownership loss retains the record hold');
echo "Pending transfer ownership and active absence remain distinct\n";$nativeSuccessful=true;
