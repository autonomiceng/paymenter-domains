<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/transfer-out-not-found-test.php';
require __DIR__.'/Support/staff-panel.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts,RenewalAutoPay,RenewalOwnership};

$nativeSuccessful=false;
staffPanel();$viewer=staffUser([Staff::VIEW]);
$money=$nativeMoney();$policy=$d->fresh()->renewal_policy;
same(true,RenewalAutoPay::ready($d->fresh()->service),'The fenced domain retains its saved Auto Pay method');
$fenced=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful();
$fenced->assertDontSee('Next invoice')->assertDontSee('Paid by Auto Pay')->assertSee(ObligationAlerts::LABELS[RenewalOwnership::REVIEW]);
same('On',DomainFacts::for($d->fresh())['Renewal']['value'],'The saved Auto-renew preference stays visible');

same('refreshed',$read(),'Positive ownership restores the billing display');
$restored=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful();
$restored->assertSee('Next invoice')->assertSee('Paid by Auto Pay');
same($money,$nativeMoney(),'Viewing the fence and recovery does not change existing debt or payments');
same($policy,$d->fresh()->renewal_policy,'Viewing the fence and recovery does not change the saved renewal preference');
echo "Staff renewal display follows ownership proof.\n";
$nativeSuccessful=true;
