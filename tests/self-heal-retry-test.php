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
expect(!HoldingObservationWork::stopped($d->fresh()),'Five transient failures retain automatic recovery');$assertFamily('Missing',cause:'The registrar read failed.',kind:'srv');same('The registrar read failed.',\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($d->fresh())['read_cause'],'The page notice names the exact cause');$assertFamily('Complete');$calls=count($state->calls);$job=(clone $q)->sole();
same(86400,$job->due_at-$clock->now()->getTimestamp(),'Five fast attempts back off for one day');
same((int)$job->due_at,\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($d->fresh())['next_read'],'The page exposes the daily recovery time');
$clock->instant=$clock->now()->modify('+86399 seconds');
same('idle',$worker->turn($queue,$guard),'Daily recovery never retries early');same($calls,count($state->calls),'Backoff sends no early registrar reads');
$clock->instant=$clock->now()->modify('+1 second');
same('read_failed',$worker->turn($queue,$guard),'A persistent failure retries on the next day');
same(86400,(clone $q)->sole()->due_at-$clock->now()->getTimestamp(),'Persistent failure keeps the slow daily backoff');
$state->fault='';$clock->instant=$clock->now()->modify('+1 day');
same('refreshed',$worker->turn($queue,$guard),'Daily recovery succeeds without staff intervention');
expect(!ObservationRecords::held($d->fresh()),'Automatic recovery clears the failed record hold');
same(0,(int)(clone $q)->sole()->tries,'Successful daily recovery restores the normal retry budget');
$q=$failFamilies();
// Persistent failures need an account check, not another identical resume loop.
require_once __DIR__.'/Support/staff-panel.php';staffPanel();
Illuminate\Support\Facades\Route::get('/admin/registrar-account/{account}',fn()=>null)->name('filament.admin.pages.registrar-account');
app('router')->getRoutes()->refreshNameLookups();
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.operations.recheck']);
Illuminate\Support\Facades\Auth::login($staff);
$notice=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::notice($d->fresh());
same('Registrar reads keep failing. Automatic retries continue.',$notice['title'],'Persistent transient failures explain their automatic recovery');
expect(!isset($notice['action']),'Automatic recovery requires no account action');
expect(!($notice['resume']??false),'The failed page does not lead with another resume');
same('Use Sync now to retry sooner.',$notice['body'],'Managing staff can retry sooner without being required to do so');
$viewer=staffUser(['admin.domains.view']);
Illuminate\Support\Facades\Auth::login($viewer);
expect(!Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::syncNow($d->fresh()),'View-only staff have no Sync now control');
$notice=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::notice($d->fresh());
same(null,$notice['body'],'View-only staff are not asked to find someone for an automatic retry');
// A proven account mismatch needs an account correction, not daily retries.
$observation->fault='owner';$clock->instant=$clock->now()->modify('+1 day');
same('domain_not_in_account',$worker->turn($queue,$guard),'A domain outside the assigned account retains the actual cause');
same(true,HoldingObservationWork::stopped($d->fresh()),'A missing account holding stops automatic reads');
$notice=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::notice($d->fresh());
same('Domain is not in the registrar account.',$notice['title'],'A missing account holding has a specific staff explanation');
expect(str_contains($notice['body'],'registration or transfer'),'The next step fixes the registrar account instead of repeating sync');
$calls=count($observation->calls);$clock->instant=$clock->now()->modify('+30 days');
same('idle',$worker->turn($queue,$guard),'A missing account holding does not loop after daily backoff');
same($calls,count($observation->calls),'No further registrar reads before the account is corrected');
$nativeSuccessful=true;echo "Bounded automatic read retries passed.\n";
