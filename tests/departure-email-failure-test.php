<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
$nativeSuccessful=false;
// A bad time zone setting breaks the email data, never the departure: the decision commits once and staff get the alert.
$emailAlert='template_failed';$emailDeliveries=[];
$emailFault=static function():Closure{$zone=config('domains.time_zone');config(['domains.time_zone'=>'Not/AZone']);return static fn()=>config(['domains.time_zone'=>$zone]);};
require __DIR__.'/Support/departure-email-failure.php';
// The departed page reads the saved departure and still shows the open email alert, so staff can act on it.
Illuminate\Support\Facades\Auth::setUser($staff);
$html=html_entity_decode(strip_tags(Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id])->assertSuccessful()->html()),ENT_QUOTES);
expect(Paymenter\Extensions\Servers\Domains\Admin\Support\DepartedPage::record($d->fresh())!==null,'The page reads the saved departure');
expect(str_contains($html,Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::alertCause('template_failed',$d->fresh())),'The departed page keeps the open email alert');
echo "Email data that fails to build leaves the staff alert and keeps the departure.\n";
$nativeSuccessful=true;
