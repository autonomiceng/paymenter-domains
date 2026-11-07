<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native retry work and real family views over a synthetic wire.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require_once __DIR__.'/Support/renewal-initialized.php';
$fixtureInitialization=static fn()=>initializedRenewalFixture();
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
// Persistent failures need an account check, not another identical resume loop.
require_once __DIR__.'/Support/staff-panel.php';staffPanel();
Illuminate\Support\Facades\Route::get('/admin/registrar-account/{account}',fn()=>null)->name('filament.admin.pages.registrar-account');
app('router')->getRoutes()->refreshNameLookups();
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.operations.recheck']);
Illuminate\Support\Facades\Auth::login($staff);
$notice=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::notice($d->fresh());
same('Registrar reads keep failing. Check that this domain is in the right registrar account.',$notice['title'],'Persistent reads explain the prerequisite');
same('Open registrar account',$notice['action']['label'],'Persistent reads offer the account check');
expect(str_ends_with($notice['action']['url']??'','/admin/registrar-account/'.$d->server_id),'The action opens this domain’s own account');
expect(!($notice['resume']??false),'The failed page does not lead with another resume');
$nativeSuccessful=true;echo "Bounded automatic read retries passed.\n";
