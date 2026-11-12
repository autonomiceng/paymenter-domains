<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native transfer with synthetic provider transports only.
declare(strict_types=1);
$fixtureTransferRegistrars=['enom'];
require __DIR__.'/Support/transfer-completion-enom-fixture.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationRecords};
$op=$prepare(placeholderComFixtureName('pending-observation-placeholder.com'));$d=$op->domain()->firstOrFail();
$observe->fault='pending';$observe->unowned=true;$before=$op->fresh()->getRawOriginal();$writes=$state->calls;
$expiry=$d->provider_expires_at;$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock));
same('healthy_pending',$observer->run($d->id),'An unowned pending transfer remains a normal pending observation');
$fresh=$d->fresh();same('transfer_pending',$fresh->status,'A pending read cannot activate the transfer');
expect(!isset($fresh->observation['record_failure'])&&!ObservationRecords::held($fresh),'Normal pending ownership creates no permanent record hold');
same($before,$op->fresh()->getRawOriginal(),'Pending observation changes no operation, funding or sealed evidence');
same($expiry,$fresh->provider_expires_at,'Pending observation cannot invent a confirmed expiry');
same($writes,$state->calls,'Observation cannot resend the transfer');
// A stopped or leased pending-phase row cannot suppress the new acquisition phase.
$oldKey=['domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>'holding'];
Illuminate\Support\Facades\DB::table('domains_observation_work')->updateOrInsert($oldKey,['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'due_at'=>0,'safe_code'=>'domain_not_in_account','claim'=>'synthetic-pending-claim','lease_until'=>$clock->now()->getTimestamp()+300]);
$old=(array)Illuminate\Support\Facades\DB::table('domains_observation_work')->where($oldKey)->sole();
$observe->unowned=false;$observe->fault='';same('succeeded',$reconcile->run($op->id)->state,'Original confirmed transfer still completes normally');
$initial=Illuminate\Support\Facades\DB::table('domains_observation_work')->where('domain_id',$d->id)->where('phase',hash('sha256',$op->operation_key))->sole();
same('initial_read',$initial->safe_code,'Activation queues fresh initial work in the acquisition phase, marked as a first read');
same($old,(array)Illuminate\Support\Facades\DB::table('domains_observation_work')->where($oldKey)->sole(),'Existing pending work and its live lease are retained');
$observe->unowned=true;
same('domain_not_in_account',$observer->run($d->id),'The same unowned snapshot still stops an active domain');
same('domain_not_in_account',$d->fresh()->observation['record_failure']['code'],'Active ownership failure retains its exact cause');
expect(ObservationRecords::held($d->fresh()),'Active ownership loss retains the record hold');
echo "Pending transfer ownership and active absence remain distinct\n";$nativeSuccessful=true;
