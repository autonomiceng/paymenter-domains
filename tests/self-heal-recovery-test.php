<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native retry work and real family views over a synthetic wire.
declare(strict_types=1);
require __DIR__.'/Support/read-retry.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema,ReadDeferred};
$q=$failFamilies();
// Legacy stopped work has no retry receipt. A repaired, verified account gets one recovery only.
$p=$d->fresh()->observation;unset($p['read_retry']);$d->fresh()->update(['observation'=>$p]);$q->update(['safe_code'=>'configuration_unverified','tries'=>5]);$state->fault='';
same('refreshed',$worker->turn($queue,$guard),'Legacy stop automatically resumes after configuration matches');
$q->update(['safe_code'=>'binding_mismatch','tries'=>1,'due_at'=>0]);$calls=count($state->calls);
same('idle',$worker->turn($queue,$guard),'Permanent binding refusal stays stopped');same($calls,count($state->calls),'Permanent refusal sends no wire');
require_once __DIR__.'/Support/staff-panel.php';
$actor=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,'admin.domains.manage']);
(new \Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords($actor->id,$d->id,$d->authority_generation,$d->account_fingerprint))->handle(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports));
expect(!HoldingObservationWork::stopped($d->fresh()),'Successful optional staff retry restores daily read work');
same('refreshed',(clone $q)->value('safe_code'),'Manual read success does not leave a stale stopped heading');
$nativeSuccessful=true;echo "Bounded automatic read recovery passed\n";
