<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic stale discovery read only.
declare(strict_types=1);
require __DIR__.'/transfer-out-not-found-test.php';
require_once __DIR__.'/Support/staff-panel.php';
use App\Models\Credit;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureProof,HoldingDeparture,HoldingLocks,MoneyOutcome,PricingRule,Refunds,StaffHoldingReview};

$nativeSuccessful=false;staffPanel();
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$prior=$d->fresh()->getAttributes();
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same(MoneyOutcome::Applied,$result->outcome,'The renewal invoice was cancelled by departure');
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$balance=static fn()=>PricingRule::minor((string)(Credit::where('user_id',$d->user_id)->where('currency_code','USD')->value('amount')??'0.00'));
$before=$balance();
// A late receipt on the departed holding is recorded; after commit it returns to the owner once as account credit.
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,'1.20','0.00','departed-after-confirm');
same(['succeeded','cancelled'],[$invoice->transactions()->sole()->status->value,$invoice->fresh()->status],'The departed receipt is recorded and the invoice stays cancelled');
same($before+120,$balance(),'The departed receipt returns to the owner after commit');
(new Refunds($clock))->creditRejected((int)$renewal->id);
same($before+120,$balance(),'A second pass credits nothing more');
same(['departed',null,true],[$d->fresh()->status,$d->fresh()->reservation_name,DepartureProof::verified($d->fresh())],'Receipt admission restores no holding authority');
echo "A receipt after departure is recorded and returned once as account credit.\n";
$nativeSuccessful=true;
