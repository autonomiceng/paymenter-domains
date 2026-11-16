<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic retained holding with unknown expiry.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
require_once __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='absent';
same('domain_not_in_account',$observer->run($d->id,readRecords:true),'Registrar absence starts the departure check');
DB::transaction(fn()=>$d->fresh()->update(['provider_expires_at'=>null]));
$clock->instant=$clock->now()->modify('+2 days');
staffPanel();$actor=staffUser([Staff::VIEW]);
$list=Livewire\Livewire::actingAs($actor)->test(ListDomains::class)->assertSuccessful()->assertCanSeeTableRecords([$d]);
$record=$list->instance()->getTableRecords()->firstWhere('id',$d->id);
$note=$list->instance()->getTable()->getColumn('note')->record($record);
// Exercise the real formatter under Laravel's warning-to-exception handler.
$html=(string)$note->formatState($note->getState());
expect(trim(strip_tags($html))!=='','A delayed holding without expiry renders its attention item');
$nativeSuccessful=true;echo "Delayed departure list renders without an expiry.\n";
