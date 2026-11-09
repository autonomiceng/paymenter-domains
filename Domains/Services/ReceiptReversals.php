<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\{Credit,Invoice};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Local gateway observations plus Domains' outside-gateway credit evidence. No money or service writes. */
final class ReceiptReversals
{
    public static function forInvoice(Invoice $seen): array
    {
        $invoice=Invoice::findOrFail($seen->id);$credits=[];$unattributed=0;
        $ops=Operation::where('invoice_id',$invoice->id)->where('currency',$invoice->currency_code)
            ->whereHas('domain',fn($q)=>$q->where('user_id',$invoice->user_id)->whereHas('service',fn($q)=>$q->where('user_id',$invoice->user_id)))
            ->orderBy('id')->limit(251)->get();
        if($ops->isEmpty()){return [];}
        if($ops->count()>250){throw new \DomainException('Invoice domain history needs review.');}
        foreach($ops as $op){
            foreach([...(isset($op->funding['refund'])?[$op->funding['refund']]:[]),...($op->funding['late_credits']??[])] as $p){
                $minor=($p['to']??null)==='account_credit'?PricingRule::minor($p['amount']):(ReceiptReturns::bridge($p)??throw new \DomainException('Retained return plan needs review.'));
                if($minor<=0){continue;}
                $payments=[];
                foreach($p['payments'] as $payment){if($payment['amount_minor']>0){$payments[$payment['id']]=$payment['amount_minor'];}}
                // Gateway fallback credits already appear in credited_minor; bridge credit covers only its other receipts.
                foreach($p['allocations']??[] as $a){$payments[$a['transaction_id']]=($payments[$a['transaction_id']]??0)-$a['amount_minor'];}
                $payments=array_filter($payments,fn($v)=>$v>0);
                if(count($payments)===1&&reset($payments)>=$minor){$payments=[array_key_first($payments)=>$minor];}
                // Older fixed-line refunds stored a whole-invoice snapshot, not a per-receipt allocation. Do not invent one.
                if(array_sum($payments)!==$minor){$unattributed+=$minor;continue;}
                foreach($payments as $id=>$amount){$credits[$id]=($credits[$id]??0)+$amount;}
            }
        }
        $balance=PricingRule::minor((string)(Credit::where('user_id',$invoice->user_id)->where('currency_code',$invoice->currency_code)->value('amount')??'0.00'));
        $result=[];$observed=[];
        foreach($invoice->transactions()->where('status',InvoiceTransactionStatus::Succeeded)->with('gateway')->orderBy('id')->get() as $payment){
            if(PricingRule::minor($payment->amount)<=0||!is_string($payment->transaction_id)||$payment->transaction_id===''||!$payment->gateway
                ||!ExtensionHelper::hasFunction($payment->gateway,'receiptReversals')){continue;}
            foreach(ExtensionHelper::call($payment->gateway,'receiptReversals',[$invoice,$payment->transaction_id]) as $r){
                if(($r['invoice_id']??null)!==(int)$invoice->id||($r['user_id']??null)!==(int)$invoice->user_id
                    ||($r['payment_intent_id']??null)!==$payment->transaction_id||strtoupper($r['currency']??'')!==$invoice->currency_code){
                    throw new \DomainException('Receipt reversal identity changed. Check the invoice payment.');
                }
                $key=json_encode([$payment->gateway_id,$payment->transaction_id,$r['kind'],$r['id']],JSON_THROW_ON_ERROR);
                if(isset($observed[$key])){continue;}$observed[$key]=true;
                $result[]=[...$r,'domains_credited_minor'=>$credits[$payment->id]??0,'domains_unattributed_minor'=>$unattributed,'credit_balance_minor'=>$balance];
            }
        }
        return $result;
    }
    /** Staff's existing Credit row, using current observations rather than an old inbox notice. */
    public static function summary(Operation $op): ?string
    {
        $invoice=$op->invoice_id?Invoice::find($op->invoice_id):null;if(!$invoice){return null;}
        $lines=[];$money=fn(int $minor)=>Storefront::money(PricingRule::decimal($minor));
        foreach(self::forInvoice($invoice) as $r){
            $line=ucfirst($r['kind']).' '.$r['status'].': Domains credit '.$money($r['domains_credited_minor']);
            if($r['domains_unattributed_minor']>0){$line.='; invoice credit without a receipt split '.$money($r['domains_unattributed_minor']);}
            if($r['credited_minor']>0){$line.='; gateway credit '.$money($r['credited_minor']);}
            $line.='; account balance '.$money($r['credit_balance_minor']).'.';
            if($r['needs_review']){$line.=$r['kind']==='dispute'&&$r['status']==='open'?' Submit evidence in Stripe before the deadline.':' Check the invoice and credit history before making an adjustment.';}
            $lines[]=$line;
        }
        return $lines?implode(' ',$lines):null;
    }
}
