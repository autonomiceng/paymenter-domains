<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic unsent card allocation only.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/money-receipt.php';
use App\Models\{Credit,Gateway};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{FundsWaits,HoldingDeparture,HoldingLocks,InvoiceMoney,MoneyDecisions,MoneyOutcome,MoneyRequest,PricingRule,ReceiptReturns,RefundPayment,StaffHoldingReview};

$nativeSuccessful=false;staffPanel();
class DepartureReleaseGateway extends MoneyReceiptGateway {
    public static array $ledger=[];
    public function receiptLedger(\App\Models\Invoice $invoice):array {return self::$ledger;}
}
class_alias(DepartureReleaseGateway::class,'Paymenter\\Extensions\\Gateways\\DepartureRelease\\DepartureRelease');
Gateway::create(['extension'=>'DepartureRelease','name'=>'Synthetic card receipt','type'=>'gateway','enabled'=>true]);
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),'DepartureRelease',$renewal->amount,'0.00','departure-local-return');
$closed=MoneyDecisions::run(new MoneyRequest($d->fresh()),function(array $l)use($renewal,$clock):bool{
    $op=$l['operations']->firstWhere('id',$renewal->id);
    FundsWaits::closeRenewal($op,$l,$clock->now(),$l['transactions']->where('invoice_id',$op->invoice_id)->pluck('id')->all());
    $money=InvoiceMoney::load($l,(int)$op->invoice_id);
    return ReceiptReturns::reserve($op,$l,RefundPayment::requireCurrent($op,$l),$money,$clock->now());
});
same(MoneyOutcome::Applied,$closed->outcome,'The closed renewal has a retained unsent card return: '.($closed->error?->getMessage()??''));
$op=$renewal->fresh();$allocation=ReceiptReturns::plans($op)[0]['allocations'][0];$key=$allocation['key'];
$balance=static fn()=>PricingRule::minor((string)(Credit::where('user_id',$d->user_id)->where('currency_code','USD')->value('amount')??'0.00'));
$before=$balance();$minor=PricingRule::minor($op->amount);
same([0,$minor],[InvoiceMoney::held(InvoiceMoney::project((int)$invoice->id)),ReceiptReturns::outstanding($op)],'The local reservation has no unreserved pool');
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$confirm=static function()use($staff,$d,$clock){
    $ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
    return (new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
};
// A sending result, including one not yet returned by the gateway, remains in flight.
$funding=$op->funding;
$op->update(['funding'=>[...$funding,'receipt_return_results'=>[$key=>['state'=>'sending','amount_minor'=>$minor]]]]);
same(HoldingDeparture::RETURN_SENDING,HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block']['line'],'A sending card return still blocks preview');
$blocked=$confirm();same([MoneyOutcome::Failed,$before],[$blocked->outcome,$balance()],'A sending card return cannot be converted');
// Restore the synthetic unsent variant without weakening retained-result protection.
DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($funding,JSON_THROW_ON_ERROR))]);
// Gateway evidence overrides the missing local result.
DepartureReleaseGateway::$ledger=[['invoice_id'=>(int)$invoice->id,'user_id'=>(int)$d->user_id,'currency'=>'USD',
    'payment_intent_id'=>'departure-local-return','allocation_key'=>$key,'state'=>'pending','amount_minor'=>$minor]];
same(HoldingDeparture::RETURN_SENDING,HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block']['line'],'A gateway-owned pending return still blocks preview');
DepartureReleaseGateway::$ledger=[];
same(null,HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block'],'A locally releasable card reservation does not block departure');
$result=$confirm();same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation converts the unsent reservation atomically');
$op=$renewal->fresh();$result=$op->funding['receipt_return_results'][$key]??[];
same(['released',$minor,'domain_departed'],[$result['state']??null,$result['credit_minor']??null,$result['reason']??null],'The existing allocation retains its exact release result');
same([$before+$minor,$minor],[$balance(),$d->fresh()->renewal_policy['departure']['credit_minor']],'Confirmation credits the old owner exactly once');
same(true,InvoiceMoney::settled(InvoiceMoney::project((int)$invoice->id)),'The converted reservation leaves the whole invoice settled');
same([0,0],[MoneyReceiptGateway::$sends,MoneyReceiptGateway::$statusCalls],'Local release performs no gateway I/O');
echo "Departure releases only local unsent card allocations and conserves their exact credit.\n";
$nativeSuccessful=true;
