<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Gateway,Invoice,InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,PricingRule,RefundPayment};
class RetiredLossGateway extends \App\Classes\Extension\Gateway
{
    public static array $ledger=[];
    public function pay(Invoice $invoice,$total){return '';}
    public function receiptLedger(Invoice $invoice):array{return self::$ledger;}
}
class_alias(RetiredLossGateway::class,'Paymenter\\Extensions\\Gateways\\RetiredLoss\\RetiredLoss');
[$op,$d,,$invoice]=$makeRenewal('retired-loss-guard-placeholder.com',false);
$cycles->retireUnpaid($op->id);
$gateway=Gateway::create(['extension'=>'RetiredLoss','name'=>'Synthetic loss','type'=>'gateway','enabled'=>true]);
$payment=InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'synthetic_retired_loss']);
$op->update(['funding'=>[...$op->funding,'late_credits'=>[['to'=>'account_credit','amount'=>$op->amount,'payments'=>[['id'=>(int)$payment->id,'amount_minor'=>PricingRule::minor($op->amount)]]]],
    'dispute_holds'=>[['dispute_id'=>'synthetic_retired_loss','payment_id'=>(int)$payment->id,'owed_minor'=>300,'removed_minor'=>100,'remainder_minor'=>200]]]]);
RetiredLossGateway::$ledger=[['invoice_id'=>(int)$invoice->id,'user_id'=>(int)$user->id,'currency'=>'USD','payment_intent_id'=>$payment->transaction_id,
    'allocation_key'=>'stripe:dispute:synthetic_retired_loss','state'=>'lost','amount_minor'=>300]];
foreach([false,true] as $advisory){
    $read=fn()=>RefundPayment::retiredSettled($op->fresh(),HoldingLocks::take($d->fresh(),advisory:$advisory));
    same(true,$advisory?$read():DB::transaction($read),'Dispute remainder does not block its retired renewal cycle');
}
// The Auto Pay equality still refuses an extra receipt, regardless of the dispute deficit.
InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>'0.01','fee'=>'0.00','status'=>'succeeded']);
same(false,DB::transaction(fn()=>RefundPayment::retiredSettled($op->fresh(),HoldingLocks::take($d->fresh()))),'An unexplained extra payment cannot grant replacement authority');
$nativeSuccessful=true;
