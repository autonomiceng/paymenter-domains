<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real encrypted models and native Filament rendering are the affected boundaries.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';

use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;
staffPanel();[, $active]=staffData(true);$actor=staffUser([Staff::VIEW]);Auth::setUser($actor);
$op=$active->operations()->sole();DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'renew']);$op=$op->fresh();$op->update(['state'=>'awaiting_payment','policy'=>['price'=>['annual_gross'=>'10.99','retained'=>true,'staff_alert'=>'retained_renewal']]]);
// This is the catalogue's explicit retained-price marker, not absent cost evidence.
$keys=ObligationAlerts::retained(['domain'=>$active,'operations'=>collect([$op])],time());
expect(!DB::table('domains_obligation_alerts')->whereIn('alert_key',$keys)->where('code','unknown_cost')->exists(),'A retained renewal price must not create a false Cost missing alert');
// Existing false rows from before this fix must disappear from the page too, without erasing history.
ObligationAlerts::record($active,'unknown_cost',time(),$op->id);
Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$active->id])->assertDontSee('Cost missing')->assertDontSee('Check request #'.$op->id);
same(1,DB::table('domains_obligation_alerts')->where('code','unknown_cost')->count(),'Read filtering keeps historical evidence');
// A second persisted synthetic case; production checkout evidence remains immutable.
DB::table('domains_operations')->where('id',$op->id)->update(['policy'=>encrypt(json_encode(['price'=>['retained'=>true,'staff_alert'=>'unknown_cost']]),false)]);
Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$active->id])->assertSee('Cost missing');
$nativeSuccessful=true;echo "Retained retail and real missing cost are distinct.\n";
