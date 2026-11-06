<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native retry work and real family views over a synthetic wire.
declare(strict_types=1);
require __DIR__.'/Support/read-retry.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema,ReadDeferred};
// Discover creates a due-now row. Yield at the existing catalogue seam before claiming any work.
$queued=$clock->now()->getTimestamp();
try {
 $worker->turn($queue,$guard,static function()use($d,$clock,$queued,$assertFamily):void {
  same($queued,(int)HoldingObservationWork::current($d->fresh())->sole()->due_at,'Discover schedules the first read now');
  $clock->instant=$clock->now()->modify('+5 seconds');$assertFamily('Checking',$queued);
  throw new ReadDeferred('Synthetic catalogue capacity yield before claim.');
 });
} catch(ReadDeferred) {}
$clock->instant=$clock->now()->setTimestamp($queued+20*60);$assertFamily('Checking',$queued); // A normal discovery batch queues up to 25 one-minute account turns.
$current=HoldingObservationWork::current($d->fresh());$current->update(['due_at'=>0,'lease_until'=>$clock->now()->getTimestamp()+300]);
$assertFamily('Checking',0); // An active worker lease is still an in-progress read, even after its due time.
$current->update(['due_at'=>$queued,'lease_until'=>0]);
$clock->instant=$clock->now()->setTimestamp($queued+31*60);$assertFamily('Missing',cause:'The scheduled registrar read is overdue.');
$nativeSuccessful=true;echo "Native queued, leased and overdue record-family views passed.\n";
