<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\{Credit,Gateway,Invoice,InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Optional gateway receipt-allocation port, dispatched by native Paymenter. Gateway owns all remote I/O. */
final class ReceiptReturns
{
    private const CLOSED_ALLOCATION = 'excess'; // Gateway-owned closed-invoice return; never allocate it here.
    public static function capable(?Gateway $gateway): bool
    {
        if(!$gateway){return false;}
        return ExtensionHelper::hasFunction($gateway,'allocateReceiptReturn')&&ExtensionHelper::hasFunction($gateway,'receiptReturnStatus');
    }
    /** Where a refund of these invoice payments would go, as reserve() splits it: 'original' when every paid row's gateway
     * can return it, 'credit' when none can, 'split' when some can. Null when nothing was paid. */
    public static function destination(iterable $rows): ?string
    {
        $paid=collect($rows)->filter(fn($r)=>($r->status===InvoiceTransactionStatus::Succeeded||$r->status===InvoiceTransactionStatus::Succeeded->value)&&PricingRule::minor((string)$r->amount)>0);
        if($paid->isEmpty()){return null;}
        $capable=$paid->filter(fn($r)=>self::capable($r->gateway))->count();
        return match(true){$capable===0=>'credit',$capable===$paid->count()=>'original',default=>'split'};
    }
    /** Caller holds the owner/invoice/operation locks. Commit the immutable plan before any gateway call. */
    public static function reserve(Operation $op,array $l,array $payment,\DateTimeImmutable $now): bool
    {
        $rows=$l['transactions']->where('invoice_id',$op->invoice_id)->where('status',InvoiceTransactionStatus::Succeeded)
            ->filter(fn($r)=>PricingRule::minor($r->amount)>0)->sortBy('id');
        if(!$rows->contains(fn($r)=>self::capable($r->gateway))){return false;}
        $peers=Operation::where('invoice_id',$op->invoice_id)->orderBy('id')->lockForUpdate()->get();$used=0;
        foreach($peers as $peer){
            foreach([...(isset($peer->funding['refund'])?[$peer->funding['refund']]:[]),...($peer->funding['late_credits']??[])] as $return){$used+=PricingRule::minor($return['amount']);}
        }
        $left=PricingRule::minor($payment['amount']);$allocations=[];$payments=[];$bridge=0;
        foreach($rows as $row){
            $gross=PricingRule::minor($row->amount);$skip=min($gross,$used);$used-=$skip;
            $minor=min($left,$gross-$skip);
            if($minor<=0){continue;}
            $payments[]=['id'=>(int)$row->id,'amount_minor'=>$minor];
            if(!self::capable($row->gateway)){$bridge+=$minor;}
            else{
                if(!is_string($row->transaction_id)||$row->transaction_id===''){throw new \DomainException('Original receipt identity is missing. Check the invoice payment.');}
                $allocations[]=['transaction_id'=>(int)$row->id,'gateway_id'=>(int)$row->gateway_id,'receipt'=>$row->transaction_id,
                    'key'=>'domains:operation:'.$op->id.':refund:receipt:'.hash('sha256',$row->transaction_id),'amount_minor'=>$minor];
            }
            $left-=$minor;if($left===0){break;}
        }
        if($left!==0){throw new \DomainException('Unreturned receipt funds are missing. Review earlier returns.');}
        $credit=null;
        if($bridge>0){
            $credit=$l['credit']??new Credit(['user_id'=>$l['user']->id,'currency_code'=>'USD','amount'=>'0.00']);
            $credit->amount=PricingRule::decimal(PricingRule::minor((string)$credit->amount)+$bridge);$credit->save();
        }
        $refund=[...$payment,'payments'=>$payments,'to'=>'original_payment','allocations'=>$allocations,
            'bridge_minor'=>$bridge,'credit_id'=>$credit?->id,'at'=>$now->format(DATE_ATOM)];
        $funding=$op->funding??[];
        $funding['refund']=$refund;
        $op->update(['funding'=>$funding]);
        if(in_array($op->action,['register','transfer'],true)){
            $l['domain']->update(['status'=>$op->action==='transfer'?'transfer_rejected':'registration_rejected']);NativeDomainGuard::terminal($l,$op);
        }
        return true;
    }
    /** Adopt the gateway-owned closed receipt without creating a second entitlement or issuing money. */
    public static function adoptClosed(Operation $op,array $l,\DateTimeImmutable $now): bool
    {
        $done=RefundPayment::lateCredited($op);$allocations=[];$payments=[];$minor=0;
        foreach($l['transactions']->where('invoice_id',$op->invoice_id)->where('status',InvoiceTransactionStatus::Succeeded)->sortBy('id') as $row){
            $gross=PricingRule::minor($row->amount);
            if($gross<=0||!self::capable($row->gateway)){continue;}
            $prior=$done[$row->id]??0;if($prior===$gross){continue;}
            if($prior!==0){throw new \DomainException('Closed receipt already has a partial return. Review the gateway return before adding credit.');}
            if(!is_string($row->transaction_id)||$row->transaction_id===''){throw new \DomainException('Original receipt identity is missing. Check the invoice payment.');}
            $payments[]=['id'=>(int)$row->id,'amount_minor'=>$gross];$minor+=$gross;
            $allocations[]=['transaction_id'=>(int)$row->id,'gateway_id'=>(int)$row->gateway_id,'receipt'=>$row->transaction_id,
                'key'=>'domains:closed:'.$op->id.':receipt:'.hash('sha256',$row->transaction_id),
                'gateway_key'=>self::CLOSED_ALLOCATION,'amount_minor'=>$gross];
        }
        if(!$allocations){return false;}
        $plan=['amount'=>PricingRule::decimal($minor),'currency'=>'USD','payments'=>$payments,'to'=>'original_payment',
            'allocations'=>$allocations,'bridge_minor'=>0,'credit_id'=>null,'at'=>$now->format(DATE_ATOM)];
        $op->update(['funding'=>[...($op->funding??[]),'late_credits'=>[...($op->funding['late_credits']??[]),$plan]]]);
        return true;
    }
    public static function plans(Operation $op): array
    {
        return array_values(array_filter(self::returns($op),fn($p)=>($p['to']??null)==='original_payment'));
    }
    /** Every retained return, the refund first and then each late credit. */
    private static function returns(Operation $op): array
    {
        return [...(isset($op->funding['refund'])?[$op->funding['refund']]:[]),...($op->funding['late_credits']??[])];
    }
    /** A retained original-payment plan's bridge credit in minor units. Null when the bridge is missing, not a nonnegative
     * integer, an allocation lacks its key or a positive integer amount, or bridge plus allocations differs from the plan
     * amount: the plan then needs staff review. */
    public static function bridge(array $plan): ?int
    {
        $bridge=$plan['bridge_minor']??null;
        if(!is_int($bridge)||$bridge<0||!is_array($plan['allocations']??null)){return null;}
        $total=$bridge;
        foreach($plan['allocations'] as $a){
            $minor=is_array($a)?($a['amount_minor']??null):null;
            if(!is_int($minor)||$minor<=0||!is_string($a['key']??null)||$a['key']===''){return null;}
            $total+=$minor;
        }
        try{return PricingRule::minor($plan['amount']??null)===$total?$bridge:null;}catch(\Throwable){return null;}
    }
    /** A retained return's amount in minor units. Null when its amount or paid-row coverage cannot be read, or an
     * original-payment plan does not balance: the return then needs staff review. */
    public static function amount(mixed $return): ?int
    {
        if(!is_array($return)||!is_array($payments=$return['payments']??[])){return null;}
        foreach($payments as $p){if(!is_array($p)||!is_int($p['id']??null)||!is_int($p['amount_minor']??null)){return null;}}
        if(($return['to']??null)==='original_payment'&&self::bridge($return)===null){return null;}
        try{return PricingRule::minor($return['amount']??null);}catch(\Throwable){return null;}
    }
    /** Any retained return, refund or late credit, that cannot be read stays with staff: callers check this before any
     * amount, allocation or coverage arithmetic. */
    public static function malformed(Operation $op): bool
    {
        foreach(self::returns($op) as $r){if(self::amount($r)===null){return true;}}
        return false;
    }
    /** Pending allocations reserve funds; completed ones already have a signed native reversal from the gateway. */
    public static function outstanding(Operation $op): int
    {
        $minor=0;
        foreach(self::plans($op) as $p){foreach($p['allocations'] as $a){
            if(!in_array($op->funding['receipt_return_results'][$a['key']]['state']??null,['refunded','credited'],true)){$minor+=$a['amount_minor'];}
        }}
        return $minor;
    }
    /** Completed returns already reduced native funding. Restore only their exact retained reversal for line coverage. */
    public static function completedMinor(Operation $op,array $l): int
    {
        return array_sum(self::reversals($op,$l['transactions']));
    }
    /** Exact completed gateway rows, so lifecycle checks can ignore expected returns and retain unrelated activity. */
    public static function reversals(Operation $op,\Illuminate\Support\Collection $transactions): array
    {
        $keys=[];
        foreach(self::plans($op) as $p){foreach($p['allocations'] as $a){
            $r=$op->funding['receipt_return_results'][$a['key']]??[];
            if(!in_array($r['state']??null,['refunded','credited'],true)){continue;}
            $key=$r['key']??null;
            $rows=$transactions->where('invoice_id',$op->invoice_id)->where('transaction_id',$key)->where('status',InvoiceTransactionStatus::Succeeded);
            if(!is_string($key)||isset($keys[$key])||$rows->count()!==1||PricingRule::minor($rows->first()->amount)!==-$a['amount_minor']){throw new \DomainException('Completed return reversal needs review.');}
            $keys[$key]=$a['amount_minor'];
        }}
        return $keys;
    }
    /** Completed automatic closed receipts are an expected pair, not new acquisition activity. */
    public static function activityTransactions(Operation $op,\Illuminate\Support\Collection $transactions): \Illuminate\Support\Collection
    {
        $keys=array_keys(self::reversals($op,$transactions));$ids=[];
        foreach(self::plans($op) as $p){foreach($p['allocations'] as $a){
            if(isset($a['gateway_key'])&&in_array($op->funding['receipt_return_results'][$a['key']]['state']??null,['refunded','credited'],true)){$ids[]=$a['transaction_id'];}
        }}
        return $transactions->whereNotIn('transaction_id',$keys)->whereNotIn('id',$ids);
    }
    public static function summary(Operation $op): ?array
    {
        $plans=self::plans($op);if(!$plans){return null;}$states=[];$bridge=0;
        if(self::malformed($op)){return ['Refund needs a check','Our team is checking the payment return. Contact support with the invoice number.'];}
        foreach(self::returns($op) as $p){if(($p['to']??null)==='account_credit'){$bridge+=PricingRule::minor($p['amount']);}}
        foreach($plans as $p){$bridge+=$p['bridge_minor'];foreach($p['allocations'] as $a){$states[]=$op->funding['receipt_return_results'][$a['key']]['state']??'reserved';}}
        if(array_intersect($states,['review'])){return ['Refund needs a check','Our team is checking the payment return. Contact support with the invoice number.'];}
        if(array_diff($states,['refunded','credited'])){return ['Refund processing','We are returning your payment and checking its progress automatically.'];}
        if(in_array('refunded',$states,true)&&($bridge>0||in_array('credited',$states,true))){return ['Money returned','Part was refunded to your original payment method. The rest is account credit because it could not be refunded.'];}
        return in_array('refunded',$states,true)?['Refunded','Your payment was refunded to your original payment method.']:['Credit added','The payment could not be refunded. The returned amount is account credit.'];
    }
    public static function resume(int $id,\DateTimeImmutable $now): ?array
    {
        OperationClaims::outside();$seen=Operation::findOrFail($id);$plans=self::plans($seen);
        if(!$plans){return null;}
        // An unbalanced plan stays with staff: no gateway call and no automatic resolution until it is reviewed.
        if(self::malformed($seen)){RefundRepairs::failed($id,$now->getTimestamp());return null;}
        try{
            $results=[];
            foreach($plans as $plan){foreach($plan['allocations'] as $a){
                $known=$seen->funding['receipt_return_results'][$a['key']]??null;
                if(($known['state']??null)==='credited'){$results[$a['key']]=$known;continue;}
                $row=InvoiceTransaction::findOrFail($a['transaction_id']);$invoice=Invoice::findOrFail($seen->invoice_id);
                if($row->invoice_id!==$seen->invoice_id||(int)$row->gateway_id!==$a['gateway_id']||$row->transaction_id!==$a['receipt']
                    ||$row->status!==InvoiceTransactionStatus::Succeeded||!self::capable($row->gateway)){
                    throw new \DomainException('Original receipt identity changed.');
                }
                if((int)$invoice->user_id!==(int)$seen->domain->user_id||$invoice->currency_code!==$seen->currency){throw new \DomainException('Receipt owner or currency changed.');}
                if(isset($a['gateway_key'])){
                    $result=ExtensionHelper::call($row->gateway,'receiptReturnStatus',[$invoice,$a['receipt'],$a['gateway_key']]);
                    $result??=['state'=>'review','amount_minor'=>$a['amount_minor'],'reason'=>'Automatic closed-invoice return is missing. Review the gateway payment.'];
                }else{
                    $result=ExtensionHelper::call($row->gateway,'receiptReturnStatus',[$invoice,$a['receipt'],$a['key']]);
                }
                if(!isset($a['gateway_key'])&&$result===null&&$known===null){
                    $result=ExtensionHelper::call($row->gateway,'allocateReceiptReturn',[$invoice,$a['receipt'],$a['key'],$a['amount_minor']]);
                }
                $result??=[...($known??[]),'state'=>'review','amount_minor'=>$a['amount_minor'],'reason'=>'Retained gateway return is missing. Review the original payment.'];
                if(!is_array($result)||($result['amount_minor']??null)!==$a['amount_minor']
                    ||!in_array($result['state']??null,['reserved','pending','refunded','credited','review'],true)){
                    throw new \DomainException('Gateway return outcome needs review.');
                }
                if(in_array($result['state'],['refunded','credited'],true)&&(!is_string($result['key']??null)||$result['key']==='')){throw new \DomainException('Completed return reversal identity is missing.');}
                $results[$a['key']]=array_intersect_key($result,array_flip(['state','amount_minor','refund_id','reason','key']));
            }}
            DB::transaction(function()use($seen,$plans,&$results):void {
                $d=$seen->domain()->firstOrFail();$l=HoldingLocks::take($d,released:$d->reservation_name===null);$op=$l['operations']->firstWhere('id',$seen->id);
                if(self::plans($op)!==$plans){throw new \DomainException('Receipt return plan changed.');}
                $saved=$op->funding['receipt_return_results']??[];
                foreach($results as $key=>$r){
                    if(($saved[$key]??null)!==($seen->funding['receipt_return_results'][$key]??null)){$results[$key]=$saved[$key];continue;}
                    if(($saved[$key]['state']??null)!=='credited'){$saved[$key]=$r;}
                }
                $funding=[...$op->funding,'receipt_return_results'=>$saved];
                $proof=clone $op;$proof->funding=$funding;self::completedMinor($proof,$l);
                $op->update(['funding'=>$funding]);
            });
            $complete=!array_filter($results,fn($r)=>!in_array($r['state'],['refunded','credited'],true));
            if(!$complete){RefundRepairs::failed($id,$now->getTimestamp());return null;}
            RefundRepairs::resolved($id,$now->getTimestamp());
            $new=array_filter($results,fn($r,$key)=>!in_array($seen->funding['receipt_return_results'][$key]['state']??null,['refunded','credited'],true),ARRAY_FILTER_USE_BOTH);
            return $new?['amount'=>PricingRule::decimal(array_sum(array_column($new,'amount_minor'))),'currency'=>'USD']:null;
        }catch(\Throwable $e){RefundRepairs::failed($id,$now->getTimestamp());throw $e;}
    }
}
