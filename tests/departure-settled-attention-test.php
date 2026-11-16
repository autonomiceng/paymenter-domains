<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/departure-credit-test.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\ObservationRecords;

$nativeSuccessful=false;
// A records read failed before staff confirmed the departure (Nov 16 walk): the record hold stays on the departed domain.
ObservationRecords::failPrimary($d->fresh(),$clock->now());
expect(ObservationRecords::held($d->fresh()),'The departed domain keeps the earlier record hold');
$attention=DomainQueries::alerting(Domain::query())->pluck('id')->map(fn($id)=>(int)$id)->all();
expect(!in_array((int)$d->id,$attention,true),'A confirmed departure leaves Needs attention');
Cache::flush();
expect(DomainQueries::openAlertCount(Domain::whereKey($d->id))===0,'A confirmed departure is not counted as needing staff action');
$found=DomainQueries::status(Domain::query(),['transferred_away'])->pluck('id')->map(fn($id)=>(int)$id)->all();
expect(in_array((int)$d->id,$found,true),'The departed domain stays findable under Transferred away');
echo "A confirmed departure is settled: it leaves Needs attention and the staff count, and stays under its status.\n";
$nativeSuccessful=true;
