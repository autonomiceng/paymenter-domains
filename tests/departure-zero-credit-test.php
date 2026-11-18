<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/reminder-mail.php';
use App\Models\Credit;
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingDeparture,HoldingLocks,InvoiceMoney,MoneyOutcome,PricingRule,StaffHoldingReview};

$nativeSuccessful=false;
staffPanel();
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$cash=static fn(int $owner)=>PricingRule::minor((string)(Credit::where('user_id',$owner)->where('currency_code','USD')->value('amount')??'0.00'));
$owner=(int)$d->user_id;$before=$cash($owner);
// The renewal was paid while under review, then the payment was refunded in full outside Domains: nothing is left to credit.
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,$renewal->amount,'0.00','departure-zero-paid');
DB::table('invoice_transactions')->insert(['invoice_id'=>$invoice->id,'amount'=>'-'.$renewal->amount,'fee'=>'0.00','transaction_id'=>'departure-zero-refund',
    'status'=>App\Enums\InvoiceTransactionStatus::Succeeded->value,'created_at'=>now(),'updated_at'=>now()]);
$preview=HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp());
same([null,[(int)$renewal->id],[(int)$invoice->id=>0]],[$preview['block'],$preview['credit'],$preview['credit_minor']??null],'A refunded paid renewal closes with a zero credit');

$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'A zero-credit departure confirms');
$held=$d->fresh();
same(['departed',0,$before],[$held->status,$held->renewal_policy['departure']['credit_minor'],$cash($owner)],'No account credit is added');
same([['operation_id'=>(int)$renewal->id,'invoice_id'=>(int)$invoice->id,'outcome'=>'credited','credit_minor'=>0]],$held->renewal_policy['departure']['invoices'],'The record names the zero credit');
same(1,count(array_filter($held->renewal_policy['staff_holdings'],fn($h)=>($h['action']??null)===HoldingDeparture::ACTION)),'The departure is recorded once');
same(true,InvoiceMoney::settled(InvoiceMoney::project((int)$invoice->id)),'Nothing is left on the closed invoice');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','template_failed')->count(),'The email data builds');
same(1,Mail::queued(DomainNotice::class)->count(),'The departure email is sent once');
same([['invoice'=>(string)($invoice->fresh()->number??$invoice->id),'credited'=>true,'amount'=>'$0.00']],Mail::queued(DomainNotice::class)->first()->data['renewals'],'The email names the zero credit as $0.00');
echo "A paid renewal refunded outside Domains departs with a zero credit and its email.\n";
$nativeSuccessful=true;
