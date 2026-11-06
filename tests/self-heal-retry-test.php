<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native retry work and real family views over a synthetic wire.
declare(strict_types=1);
require __DIR__.'/Support/read-retry.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema,ReadDeferred};
$observation->during=static fn()=>throw new DomainException('Registrar read failed.');
same('read_failed',$worker->turn($queue,$guard),'Transient primary failure is retained');
expect(!HoldingObservationWork::stopped($d->fresh()),'First transient failure schedules an automatic retry');
$assertFamily('Missing',cause:'The registrar read failed.');
$q=HoldingObservationWork::current($d->fresh());$job=(clone $q)->sole();same(60,$job->due_at-$clock->now()->getTimestamp(),'First retry uses bounded backoff');
same('idle',$worker->turn($queue,$guard),'No retry before due time');
$clock->instant=$clock->now()->modify('+60 seconds');same('refreshed',$worker->turn($queue,$guard),'Transient recovery needs no staff action');
expect(!ObservationRecords::held($d->fresh()),'Successful full-family retry clears stale failure hold');
// A family failure is retried even though the primary facts succeeded.
$q=$failFamilies();
expect(HoldingObservationWork::stopped($d->fresh()),'Five failures stop with their exact cause');$assertFamily('Missing',cause:'The registrar read failed.',kind:'srv',stopped:true);same('The registrar read failed.',\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($d->fresh())['read_cause'],'The page notice names the exact cause');$assertFamily('Complete');$calls=count($state->calls);$clock->instant=$clock->now()->modify('+2 days');
same('idle',$worker->turn($queue,$guard),'Exhausted current work stays stopped');same($calls,count($state->calls),'Exhaustion does not loop');
$nativeSuccessful=true;echo "Bounded automatic read retries passed.\n";
