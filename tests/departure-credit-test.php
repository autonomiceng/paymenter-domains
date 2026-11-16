<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/transfer-out-not-found-test.php';
require_once __DIR__.'/Support/staff-panel.php';
use App\Models\{Credit,User};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingDeparture,HoldingLocks,InvoiceMoney,MoneyOutcome,PricingRule,RenewalCycles,StaffHoldingReview};

$nativeSuccessful=false;
staffPanel();
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$cash=static fn(int $owner)=>PricingRule::minor((string)(Credit::where('user_id',$owner)->where('currency_code','USD')->value('amount')??'0.00'));
// Another customer's credit must not move.
$stranger=User::create(['first_name'=>'Other','last_name'=>'Customer','email'=>'other-customer@example.test','password'=>'synthetic-password']);Credit::create(['user_id'=>$stranger->id,'currency_code'=>'USD','amount'=>'3.00']);
$owner=(int)$d->user_id;$before=$cash($owner);$amount=PricingRule::minor((string)$renewal->amount);

// The renewal is paid in full while the holding is under review, so it is never sent.
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,$renewal->amount,'0.00','departure-paid-unsent');
same(['paid',true],[$invoice->fresh()->status,RenewalCycles::registrarVirgin($renewal->fresh())],'The renewal is paid and never sent');
$preview=HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp());
same(null,$preview['block'],'A paid unsent renewal does not block confirmation');
same([[],[(int)$renewal->id]],[$preview['close'],$preview['credit']??null],'The preview returns the paid unsent renewal as credit');

$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation closes the paid unsent renewal');
same($before+$amount,$cash($owner),"The full payment returns as the old owner's account credit");
same(300,$cash((int)$stranger->id),"Another customer's credit is unchanged");
$held=$d->fresh();$op=$renewal->fresh();
same(['departed',$amount],[$held->status,$held->renewal_policy['departure']['credit_minor']],'The frozen record carries the new credit');
same(['rejected','renewal_funds_expired','cancelled'],[$op->state,$op->safe_code,$invoice->fresh()->status],'The request closes unsent and its invoice is cancelled');
same([['operation_id'=>(int)$op->id,'invoice_id'=>(int)$invoice->id,'outcome'=>'credited','credit_minor'=>$amount]],$held->renewal_policy['departure']['invoices'],'The record names the credited invoice');
same(true,InvoiceMoney::settled(InvoiceMoney::project((int)$invoice->id)),'Nothing is left on the closed invoice');
echo "A paid unsent renewal closes as the old owner's account credit inside the departure decision.\n";
$nativeSuccessful=true;
