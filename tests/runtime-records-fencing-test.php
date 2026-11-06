<?php
// Native producer gates and customer order/copy pairs. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/runtime-records.php';
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,RuntimeWork,ObservationWork,ObservationRecords,OwnerRecords,ManagementPayload,ReadDeferred,DomainObservation};
Queue::fake();$finance=[$original->fresh()->getAttributes(),$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->confirmed_revision,$d->renewal_policy];
$registerRecords();app(ObservationWork::class)->enroll($d->server_id);
// Acceptance can disappear without changing locked rows; it is checked again before publication.
$state->during=static function()use(&$compositionReady):void{$compositionReady=false;};$dueRecords();
try{app(DomainObservation::class)->run($d->id);expect(false,'Mid-flight acceptance loss must defer publication');}catch(ReadDeferred){}
same(null,ObservationRecords::current($d->fresh()),'No inventory committed after acceptance loss');$compositionReady=true;
// Pausing mid-flight discards record publication; independent snapshots continue on later paused ticks.
$state->during=fn()=>$bindings->setProcessing($d->server_id,false);$dueRecords();
try{app(DomainObservation::class)->run($d->id);expect(false,'Mid-flight pause must defer publication');}catch(ReadDeferred){}
same(null,ObservationRecords::current($d->fresh()),'No inventory committed after processing pause');$state->calls=[];$observation->years=2;$dueRecords();$runtimePorts->initialManagedRecordObservations();
same([],$state->calls,'Paused refresh never calls record transport');same('2028-10-18',$d->fresh()->observation['facts']['expiry'],'Paused records do not freeze independent expiry observations');
same($finance,[$original->fresh()->getAttributes(),$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->fresh()->confirmed_revision,$d->fresh()->renewal_policy],'Record producer never changes financial authority');
echo "Native record publication fences and independent paused core reads passed.\n";$nativeSuccessful=true;
