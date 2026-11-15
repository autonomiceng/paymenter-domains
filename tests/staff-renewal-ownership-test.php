<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/transfer-out-not-found-test.php';
require __DIR__.'/Support/staff-panel.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains,ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff,StaffDomainInputs};
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts,RenewalAutoPay,RenewalOwnership};

$nativeSuccessful=false;
staffPanel();$viewer=staffUser([Staff::VIEW]);Illuminate\Support\Facades\Auth::setUser($viewer);
$money=$nativeMoney();$policy=$d->fresh()->renewal_policy;
same(true,RenewalAutoPay::ready($d->fresh()->service),'The fenced domain retains its saved Auto Pay method');
$facts=DomainFacts::for($d->fresh());
same(['Renewal','warning'],DomainFacts::ALERTS[RenewalOwnership::REVIEW]??null,'Departure review has its registered billing row and tone');
same(true,$facts['Renewal']['alert']??false,'The fenced holding presents a registered Renewal alert');
same(false,in_array(ObligationAlerts::LABELS[RenewalOwnership::REVIEW],$facts['Open alerts']['value']??[],true),'Departure review needs no Open alerts fallback');
$live=static fn()=>array_column(StaffDomainInputs::for([$d->fresh()])[$d->id]['alerts'],'code');
same(true,in_array(RenewalOwnership::REVIEW,$live(),true),'Current ownership absence keeps the review live');
$list=Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($d->domain)->assertCanSeeTableRecords([$d]);
same(DomainResource::getUrl('view',['record'=>$d->id]),$list->instance()->getTable()->getRecordUrl($d->fresh()),'The registered alert opens its staff domain page');
$fenced=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful();
$fenced->assertDontSee('Next invoice')->assertDontSee('Paid by Auto Pay')->assertSee(ObligationAlerts::LABELS[RenewalOwnership::REVIEW]);
same('On',DomainFacts::for($d->fresh())['Renewal']['value'],'The saved Auto-renew preference stays visible');

same('refreshed',$read(),'Positive ownership restores the billing display');
$restored=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful();
$restored->assertSee('Next invoice')->assertSee('Paid by Auto Pay');
// A stale saved row must not revive an alert whose current-state predicate has cleared.
ObligationAlerts::record($d->fresh(),RenewalOwnership::REVIEW,$clock->now()->getTimestamp());
same(false,in_array(RenewalOwnership::REVIEW,$live(),true),'Positive current ownership hides even an unresolved stale review');
same(false,DomainFacts::for($d->fresh())['Renewal']['alert']??false,'The registered Renewal alert clears after positive ownership');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$d->id])->assertDontSee(ObligationAlerts::LABELS[RenewalOwnership::REVIEW]);
same($money,$nativeMoney(),'Viewing the fence and recovery does not change existing debt or payments');
same($policy,$d->fresh()->renewal_policy,'Viewing the fence and recovery does not change the saved renewal preference');
echo "Staff renewal display follows ownership proof.\n";
$nativeSuccessful=true;
