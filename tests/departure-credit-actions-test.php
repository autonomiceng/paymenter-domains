<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/money-receipt.php';
require_once __DIR__.'/Support/fixture-cases.php';
use App\Enums\InvoiceTransactionStatus;
use App\Models\{Credit,Gateway,InvoiceTransaction,Service,User};
use Illuminate\Support\Facades\{Crypt,DB,Mail};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureProof,EnomAnswers,FundsWaits,HoldingDeparture,HoldingLocks,InvoiceMoney,MoneyDecisions,MoneyOutcome,MoneyRequest,PricingRule,ReceiptReturns,Refunds,RefundPayment,RenewalCycles,RenewalRetries,StaffHoldingReview};
// Every case starts from the same issued renewal followed by the registrar's definite absence. Each case adds its own
// payment history, then runs the real staff departure decision or refund sweep against it.
$nativeSuccessful=false;
staffPanel();
class DepartureReleaseGateway extends MoneyReceiptGateway {
    public static array $ledger=[];
    public function receiptLedger(\App\Models\Invoice $invoice):array {return self::$ledger;}
}
class_alias(DepartureReleaseGateway::class,'Paymenter\\Extensions\\Gateways\\DepartureRelease\\DepartureRelease');
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$cash=static fn(int $owner)=>PricingRule::minor((string)(Credit::where('user_id',$owner)->where('currency_code','USD')->value('amount')??'0.00'));
$owner=(int)$d->user_id;$amount=PricingRule::minor((string)$renewal->amount);
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$confirm=static function()use($staff,$d,$clock){
    $ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
    return (new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
};
// Pin each case to the prepared read and Carbon clocks explicitly, whatever a reset restores.
$pinned=[$clock->now(),Carbon\Carbon::getTestNow(),Carbon\CarbonImmutable::getTestNow()];
$pin=static function()use($clock,$pinned):void{[$clock->instant,$native,$immutable]=$pinned;Carbon\Carbon::setTestNow($native);Carbon\CarbonImmutable::setTestNow($immutable);};
$fixed=compact('d','invoice','renewal','cash','owner','amount','confirm','clock','pin');

// A card-funded paid unsent renewal closes as the old owner's account credit inside the departure decision.
$cases['card-credit']=static function()use($fixed):void {extract($fixed);
    $pin();
    Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic card receipt','type'=>'gateway','enabled'=>true]);
    // Another customer's credit must not move.
    $stranger=User::create(['first_name'=>'Other','last_name'=>'Customer','email'=>'other-customer@example.test','password'=>'synthetic-password']);Credit::create(['user_id'=>$stranger->id,'currency_code'=>'USD','amount'=>'3.00']);
    $before=$cash($owner);
    // The renewal is paid in full while the holding is under review, so it is never sent.
    App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),'MoneyReceipt',$renewal->amount,'0.00','departure-paid-unsent');
    $receipt=$invoice->transactions()->where('transaction_id','departure-paid-unsent')->sole();
    same($amount,InvoiceMoney::heldFor(InvoiceMoney::project((int)$invoice->id),(int)$receipt->id)['card'],'The paid share is return-capable card money');
    same(['paid',true],[$invoice->fresh()->status,RenewalCycles::registrarVirgin($renewal->fresh())],'The renewal is paid and never sent');
    $preview=HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp());
    same(null,$preview['block'],'A paid unsent renewal does not block confirmation');
    same([[],[(int)$renewal->id]],[$preview['close'],$preview['credit']??null],'The preview returns the paid unsent renewal as credit');
    $result=$confirm();
    same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation closes the paid unsent renewal');
    same($before+$amount,$cash($owner),"The full payment returns as the old owner's account credit");
    same(300,$cash((int)$stranger->id),"Another customer's credit is unchanged");
    $held=$d->fresh();$op=$renewal->fresh();
    same(['departed',$amount],[$held->status,$held->renewal_policy['departure']['credit_minor']],'The frozen record carries the new credit');
    same(['rejected','renewal_funds_expired','cancelled'],[$op->state,$op->safe_code,$invoice->fresh()->status],'The request closes unsent and its invoice is cancelled');
    same([['operation_id'=>(int)$op->id,'invoice_id'=>(int)$invoice->id,'outcome'=>'credited','credit_minor'=>$amount]],$held->renewal_policy['departure']['invoices'],'The record names the credited invoice');
    same(true,InvoiceMoney::settled(InvoiceMoney::project((int)$invoice->id)),'Nothing is left on the closed invoice');
    same([0,0],[MoneyReceiptGateway::$sends,MoneyReceiptGateway::$statusCalls],'Departure credits local card money without gateway I/O');
};

// Departure releases only local unsent card allocations and conserves their exact credit.
$cases['card-release']=static function()use($fixed):void {extract($fixed);
    $pin();
    Gateway::create(['extension'=>'DepartureRelease','name'=>'Synthetic card receipt','type'=>'gateway','enabled'=>true]);
    App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),'DepartureRelease',$renewal->amount,'0.00','departure-local-return');
    $closed=MoneyDecisions::run(new MoneyRequest($d->fresh()),function(array $l)use($renewal,$clock):bool{
        $op=$l['operations']->firstWhere('id',$renewal->id);
        FundsWaits::closeRenewal($op,$l,$clock->now(),$l['transactions']->where('invoice_id',$op->invoice_id)->pluck('id')->all());
        $money=InvoiceMoney::load($l,(int)$op->invoice_id);
        return ReceiptReturns::reserve($op,$l,RefundPayment::requireCurrent($op,$l),$money,$clock->now());
    });
    same(MoneyOutcome::Applied,$closed->outcome,'The closed renewal has a retained unsent card return: '.($closed->error?->getMessage()??''));
    $op=$renewal->fresh();$allocation=ReceiptReturns::plans($op)[0]['allocations'][0];$key=$allocation['key'];
    $before=$cash($owner);$minor=PricingRule::minor($op->amount);
    same([0,$minor],[InvoiceMoney::held(InvoiceMoney::project((int)$invoice->id)),ReceiptReturns::outstanding($op)],'The local reservation has no unreserved pool');
    // A sending result, including one not yet returned by the gateway, remains in flight.
    $funding=$op->funding;
    $op->update(['funding'=>[...$funding,'receipt_return_results'=>[$key=>['state'=>'sending','amount_minor'=>$minor]]]]);
    same(HoldingDeparture::RETURN_SENDING,HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block']['line'],'A sending card return still blocks preview');
    $blocked=$confirm();same([MoneyOutcome::Failed,$before],[$blocked->outcome,$cash($owner)],'A sending card return cannot be converted');
    // Restore the synthetic unsent variant without weakening retained-result protection.
    DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>Crypt::encryptString(json_encode($funding,JSON_THROW_ON_ERROR))]);
    // Gateway evidence overrides the missing local result.
    DepartureReleaseGateway::$ledger=[['invoice_id'=>(int)$invoice->id,'user_id'=>(int)$d->user_id,'currency'=>'USD',
        'payment_intent_id'=>'departure-local-return','allocation_key'=>$key,'state'=>'pending','amount_minor'=>$minor]];
    same(HoldingDeparture::RETURN_SENDING,HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block']['line'],'A gateway-owned pending return still blocks preview');
    DepartureReleaseGateway::$ledger=[];
    same(null,HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block'],'A locally releasable card reservation does not block departure');
    $result=$confirm();same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation converts the unsent reservation atomically');
    $op=$renewal->fresh();$result=$op->funding['receipt_return_results'][$key]??[];
    same(['released',$minor,'domain_departed'],[$result['state']??null,$result['credit_minor']??null,$result['reason']??null],'The existing allocation retains its exact release result');
    same([$before+$minor,$minor],[$cash($owner),$d->fresh()->renewal_policy['departure']['credit_minor']],'Confirmation credits the old owner exactly once');
    same(true,InvoiceMoney::settled(InvoiceMoney::project((int)$invoice->id)),'The converted reservation leaves the whole invoice settled');
    same([0,0],[MoneyReceiptGateway::$sends,MoneyReceiptGateway::$statusCalls],'Local release performs no gateway I/O');
};

// A closed renewal with free money returns it as credit inside the departure decision.
$cases['closed-credit']=static function()use($fixed):void {extract($fixed);
    $pin();
    $before=$cash($owner);
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
    $result=$confirm();
    same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation credits the closed renewal');
    same($before+$amount,$cash($owner),"The free money returns once as the old owner's account credit");
    $held=$d->fresh();
    same(['departed',$amount],[$held->status,$held->renewal_policy['departure']['credit_minor']],'The frozen record carries the new credit');
    same([['operation_id'=>(int)$renewal->id,'invoice_id'=>(int)$invoice->id,'outcome'=>'credited','credit_minor'=>$amount]],$held->renewal_policy['departure']['invoices'],'The record names the credited invoice');
    same(true,InvoiceMoney::settled(InvoiceMoney::project((int)$invoice->id)),'Nothing is left on the closed invoice');
};

// A late card receipt on a departed holding credits the old owner and never touches the new holding.
$cases['late-card']=static function()use($fixed):void {extract($fixed);
    $pin();
    Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic card receipt','type'=>'gateway','enabled'=>true]);
    $result=$confirm();
    same([MoneyOutcome::Applied,'cancelled'],[$result->outcome,$invoice->fresh()->status],'The departure closes the unpaid renewal invoice');
    $departed=$d->fresh()->getAttributes();
    // Another customer now holds the same name on a new row.
    $stranger=User::create(['first_name'=>'New','last_name'=>'Holder','email'=>'new-holder@example.test','password'=>'synthetic-password']);
    Credit::create(['user_id'=>$stranger->id,'currency_code'=>'USD','amount'=>'2.00']);
    $old=$d->service;
    $service=Service::withoutEvents(fn()=>Service::create(['user_id'=>$stranger->id,'order_id'=>$old->order_id,'product_id'=>$old->product_id,'plan_id'=>$old->plan_id,'status'=>'active','quantity'=>1,'price'=>$old->price,'currency_code'=>'USD']));
    $row=DB::table('domains_domains')->where('id',$d->id)->first();$row=(array)$row;unset($row['id']);
    $newId=(int)DB::table('domains_domains')->insertGetId([...$row,'user_id'=>$stranger->id,'service_id'=>$service->id,'reservation_name'=>$d->domain,'status'=>'active','renewal_policy'=>null]);
    $newRow=(array)DB::table('domains_domains')->where('id',$newId)->first();
    $before=$cash($owner);
    // A stale pay form pays the old cancelled renewal invoice after the departure.
    App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),'MoneyReceipt',$renewal->amount,'0.00','departed-late-receipt');
    same(1,InvoiceTransaction::where('invoice_id',$invoice->id)->where('transaction_id','departed-late-receipt')->count(),'The late receipt is kept on the old invoice');
    $refunds=new Refunds($clock);$refunds->creditRejected((int)$renewal->id);$refunds->creditRejected((int)$renewal->id);
    same($before+$amount,$cash($owner),'The late receipt returns once as the old owner\'s account credit');
    same(200,$cash((int)$stranger->id),"The new holder's credit is unchanged");
    same($newRow,(array)DB::table('domains_domains')->where('id',$newId)->first(),'The new holding is untouched');
    same(0,DB::table('domains_operations')->where('domain_id',$newId)->count(),'The new holding gets no renewal or refund work');
    $held=$d->fresh();
    same(['departed',null,true,'cancelled'],[$held->status,$held->reservation_name,DepartureProof::verified($held),$held->service->status],'The departed row restores no name, service or renewal');
    same(['rejected','renewal_cancelled_unpaid'],[$renewal->fresh()->state,$renewal->fresh()->safe_code],'The closed renewal stays closed');
    same($departed['renewal_policy'],$held->getAttributes()['renewal_policy'],'The frozen record is unchanged');
    same([],ReceiptReturns::plans($renewal->fresh()),'A locally available late card receipt needs no gateway return plan');
    same([0,0],[MoneyReceiptGateway::$sends,MoneyReceiptGateway::$statusCalls],'A departure credits local money without gateway return calls');
};

// A receipt after departure is recorded and returned once as account credit.
$cases['receipt-status']=static function()use($fixed):void {extract($fixed);
    $pin();
    $result=$confirm();
    same(MoneyOutcome::Applied,$result->outcome,'The renewal invoice was cancelled by departure');
    app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
    $before=$cash($owner);
    // A late receipt on the departed holding is recorded. Departure closed the renewal before it, so the return sweep credits
    // it to the owner once, on the native late path.
    App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,'1.20','0.00','departed-after-confirm');
    same(['succeeded','cancelled'],[$invoice->transactions()->sole()->status->value,$invoice->fresh()->status],'The departed receipt is recorded and the partly paid invoice stays cancelled');
    (new Refunds($clock))->creditRejected((int)$renewal->id);
    same($before+120,$cash($owner),'The departed receipt returns to the owner once');
    (new Refunds($clock))->creditRejected((int)$renewal->id);
    same($before+120,$cash($owner),'A second pass credits nothing more');
    same(['departed',null,true],[$d->fresh()->status,$d->fresh()->reservation_name,DepartureProof::verified($d->fresh())],'Receipt admission restores no holding authority');
};

// Resolved retry history follows its completed child at departure.
$cases['retry-history']=static function()use($fixed):void {extract($fixed);
    $pin();
    $first=$renewal;
    // The renewal was paid, its first attempt was refused before sending and a retry on the same paid invoice completed.
    App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,$first->amount,'0.00','departure-retry-paid');
    $childKey=$first->operation_key.':replacement:1';
    $row=$first->fresh()->getRawOriginal();unset($row['id']);
    $row=[...$row,'operation_key'=>$childKey,'retry_number'=>1,'state'=>'succeeded',
        'renewal_previous_proof'=>EnomAnswers::identity($first)];
    DB::table('domains_operations')->insert($row);
    $child=Operation::where('operation_key',$childKey)->sole();
    $first->fresh()->update(['state'=>'rejected','safe_code'=>'renewal_definitely_unsent']);
    same(true,RenewalRetries::resolved($first->fresh(),$d->fresh()),'The first attempt is resolved retry history');
    $before=$cash($owner);
    $preview=HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp());
    same(null,$preview['block'],'Resolved retry history with a completed child does not block confirmation');
    same([[],[],[(int)$child->id]],[$preview['close'],$preview['credit'],$preview['kept']],'The completed child keeps the paid invoice');
    $result=$confirm();
    same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation completes with resolved retry history');
    $held=$d->fresh();
    same(['departed',0],[$held->status,$held->renewal_policy['departure']['credit_minor']],'No credit is returned for the earned renewal');
    same([['operation_id'=>(int)$child->id,'invoice_id'=>(int)$invoice->id,'outcome'=>'kept']],$held->renewal_policy['departure']['invoices'],'The record keeps the completed child invoice');
    same([$before,'paid','rejected','succeeded'],[$cash($owner),$invoice->fresh()->status,$first->fresh()->state,$child->fresh()->state],'Money, invoice and both attempts stay as they were');
};

// A paid renewal refunded outside Domains departs with a zero credit and its email.
$cases['zero-credit']=static function()use($fixed,$fixture):void {extract($fixed);
    $pin();
    // The mail shell and fakes belong to this case only; the reset restores the container, config and schema after it.
    require __DIR__.'/Support/reminder-mail.php';
    $before=$cash($owner);
    // The renewal was paid while under review, then the payment was refunded in full outside Domains: nothing is left to credit.
    App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,$renewal->amount,'0.00','departure-zero-paid');
    DB::table('invoice_transactions')->insert(['invoice_id'=>$invoice->id,'amount'=>'-'.$renewal->amount,'fee'=>'0.00','transaction_id'=>'departure-zero-refund',
        'status'=>InvoiceTransactionStatus::Succeeded->value,'created_at'=>now(),'updated_at'=>now()]);
    $preview=HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp());
    same([null,[(int)$renewal->id],[(int)$invoice->id=>0]],[$preview['block'],$preview['credit'],$preview['credit_minor']??null],'A refunded paid renewal closes with a zero credit');
    $result=$confirm();
    same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'A zero-credit departure confirms');
    $held=$d->fresh();
    same(['departed',0,$before],[$held->status,$held->renewal_policy['departure']['credit_minor'],$cash($owner)],'No account credit is added');
    same([['operation_id'=>(int)$renewal->id,'invoice_id'=>(int)$invoice->id,'outcome'=>'credited','credit_minor'=>0]],$held->renewal_policy['departure']['invoices'],'The record names the zero credit');
    same(1,count(array_filter($held->renewal_policy['staff_holdings'],fn($h)=>($h['action']??null)===HoldingDeparture::ACTION)),'The departure is recorded once');
    same(true,InvoiceMoney::settled(InvoiceMoney::project((int)$invoice->id)),'Nothing is left on the closed invoice');
    same(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','template_failed')->count(),'The email data builds');
    same(1,Mail::queued(DomainNotice::class)->count(),'The departure email is sent once');
    same([['invoice'=>(string)($invoice->fresh()->number??$invoice->id),'credited'=>true,'amount'=>'$0.00']],Mail::queued(DomainNotice::class)->first()->data['renewals'],'The email names the zero credit as $0.00');
};
nativeFixtureCases($cases,compact('clock','observation','state','observer','billing','d','user','invoice','renewal','staff'));
echo "Departure credit actions keep the staff decision, refund sweep and each payment history.\n";
$nativeSuccessful=true;
