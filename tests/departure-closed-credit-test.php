<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/staff-panel.php';
use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{FundsWaits,HoldingDeparture,HoldingLocks,InvoiceMoney,MoneyOutcome,PricingRule,StaffHoldingReview};

$nativeSuccessful=false;
staffPanel();
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$cash=static fn(int $owner)=>PricingRule::minor((string)(Credit::where('user_id',$owner)->where('currency_code','USD')->value('amount')??'0.00'));
$owner=(int)$d->user_id;$before=$cash($owner);$amount=PricingRule::minor((string)$renewal->amount);
// The renewal was paid, then the funds closure closed it unsent; the refund sweep has not credited the money yet.
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,$renewal->amount,'0.00','departure-closed-paid');
DB::transaction(function()use($d,$renewal,$clock):void{
    $l=HoldingLocks::take($d->fresh());$op=$l['operations']->firstWhere('id',$renewal->id);
    FundsWaits::closeRenewal($op,$l,$clock->now(),$l['transactions']->where('invoice_id',$op->invoice_id)->where('status',InvoiceTransactionStatus::Succeeded)->pluck('id')->map(fn($id)=>(int)$id)->values()->all());
});
same(['rejected',FundsWaits::RENEWAL_CLOSED,'cancelled',$before],[$renewal->fresh()->state,$renewal->fresh()->safe_code,$invoice->fresh()->status,$cash($owner)],'The renewal is closed unsent with its money still on the invoice');

$preview=HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp());
same(null,$preview['block'],'A closed renewal with free money does not block confirmation');
same([(int)$renewal->id],$preview['credit'],'The preview returns the closed renewal money as credit');
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation credits the closed renewal');
same($before+$amount,$cash($owner),"The free money returns once as the old owner's account credit");
$held=$d->fresh();
same(['departed',$amount],[$held->status,$held->renewal_policy['departure']['credit_minor']],'The frozen record carries the new credit');
same([['operation_id'=>(int)$renewal->id,'invoice_id'=>(int)$invoice->id,'outcome'=>'credited','credit_minor'=>$amount]],$held->renewal_policy['departure']['invoices'],'The record names the credited invoice');
same(true,InvoiceMoney::settled(InvoiceMoney::project((int)$invoice->id)),'Nothing is left on the closed invoice');
echo "A closed renewal with free money returns it as credit inside the departure decision.\n";
$nativeSuccessful=true;
