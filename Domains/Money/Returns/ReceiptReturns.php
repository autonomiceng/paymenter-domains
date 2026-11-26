<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Returns;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\{Credit,Gateway,Invoice,InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, InvoiceMoney, InvoiceScope, MoneyDecisions, MoneyOutcome, MoneyRequest, MoneyResult, MoneyScope, NativeDomainGuard, OperationClaims, PricingRule};
/** Optional gateway receipt-allocation port, dispatched by native Paymenter. Gateway owns all remote I/O. */
final class ReceiptReturns
{
    private const CLOSED_ALLOCATION = 'excess'; // Gateway-owned closed-invoice return; never allocate it here.
    /** Title and customer words for a retained return that needs a person to check it. */
    public const REVIEW=['Refund needs a check','Our team is checking the payment return. Contact support with the invoice number.'];
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
    public static function reserve(Operation $op,array $l,array $payment,array $money,\DateTimeImmutable $now): bool
    {
        if(InvoiceMoney::held($money)===0){return true;}
        if(!array_filter($payment['payments'],fn($p)=>InvoiceMoney::heldFor($money,$p['id'])['card']>0)){return false;}
        $allocations=[];$bridge=0;
        foreach($payment['payments'] as $p){
            $row=$l['transactions']->firstWhere('id',$p['id']);$minor=$p['amount_minor'];
            if(InvoiceMoney::heldFor($money,$p['id'])['card']===0){$bridge+=$minor;}
            else{
                if(!is_string($row->transaction_id)||$row->transaction_id===''){throw new \DomainException('Original receipt identity is missing. Check the invoice payment.');}
                $allocations[]=['transaction_id'=>(int)$row->id,'gateway_id'=>(int)$row->gateway_id,'receipt'=>$row->transaction_id,
                    'key'=>'domains:operation:'.$op->id.':refund:receipt:'.hash('sha256',$row->transaction_id),'amount_minor'=>$minor];
            }
        }
        $credit=null;
        if($bridge>0){
            $credit=$l['credit']??new Credit(['user_id'=>$l['user']->id,'currency_code'=>'USD','amount'=>'0.00']);
            $credit->amount=PricingRule::decimal(PricingRule::minor((string)$credit->amount)+$bridge);$credit->save();
        }
        $refund=[...$payment,'to'=>'original_payment','allocations'=>$allocations,
            'bridge_minor'=>$bridge,'credit_id'=>$credit?->id,'at'=>$now->format(DATE_ATOM)];
        $funding=$op->funding??[];
        $funding['refund']=$refund;
        $op->update(['funding'=>$funding]);
        if(in_array($op->action,['register','transfer'],true)){
            $l['domain']->update(['status'=>$op->action==='transfer'?'transfer_rejected':'registration_rejected']);NativeDomainGuard::terminal($l,$op);
        }
        return true;
    }
    /** Adopt the gateway-owned closed receipt without issuing money. Departure adopts existing allocations only;
     * its locally available receipts return as account credit. */
    public static function adoptClosed(Operation $op,array $l,\DateTimeImmutable $now,bool $existingOnly=false): bool
    {
        $money=InvoiceMoney::load($l,(int)$op->invoice_id);$done=RefundPayment::lateCredited($op);$allocations=[];$payments=[];$minor=0;
        foreach($l['transactions']->where('invoice_id',$op->invoice_id)->where('status',InvoiceTransactionStatus::Succeeded)->sortBy('id') as $row){
            if(PricingRule::minor($row->amount)<=0||!self::capable($row->gateway)){continue;}
            $entry=collect($money['ledger'])->first(fn($r)=>$r['gateway_id']===(int)$row->gateway_id&&$r['payment_intent_id']===$row->transaction_id&&$r['allocation_key']===self::CLOSED_ALLOCATION);
            if($existingOnly&&!$entry){continue;}
            $gross=$entry?(int)$entry['amount_minor']:InvoiceMoney::heldFor($money,(int)$row->id)['card'];
            $prior=$done[$row->id]??0;if($gross===0||$prior===$gross){continue;}
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
    /** Active allocations and actual per-receipt coverage; stored plans remain immutable. */
    public static function effective(Operation $op): array
    {
        $plans=self::returns($op);
        foreach($plans as &$p){
            if(self::amount($p)===null){throw new \DomainException('Retained return plan needs review.');}
            foreach($p['allocations']??[] as $i=>$a){
                $r=$op->funding['receipt_return_results'][$a['key']]??[];
                if(($r['state']??null)!=='released'){continue;}
                $credit=$r['credit_minor']??null;
                if(!is_int($credit)||$credit<0||$credit>$a['amount_minor']){throw new \DomainException('Released credit needs review.');}
                $drop=$a['amount_minor']-$credit;$p['amount']=PricingRule::decimal(PricingRule::minor($p['amount'])-$drop);
                $p['bridge_minor']+=$credit;unset($p['allocations'][$i]);
                if($credit>0){$p['credit_id']=$r['credit_id']??null;}
                foreach($p['payments'] as &$payment){if($payment['id']===$a['transaction_id']){$payment['amount_minor']-=$drop;break;}}unset($payment);
            }
        }unset($p);
        return $plans;
    }
    /** Isolate the original refund and its retained allocation results from later returns. */
    public static function originalRefund(Operation $op): Operation
    {
        $original=clone $op;$refund=$op->funding['refund']??null;
        $claim = array_intersect_key($op->funding ?? [], ['claim' => true, 'seal' => true]);
        $original->funding=[...$claim,'refund'=>$refund,'receipt_return_results'=>array_intersect_key(
            $op->funding['receipt_return_results']??[],array_flip(array_column($refund['allocations']??[],'key')))];
        return $original;
    }
    /** Every retained return, the refund first and then each late credit. */
    private static function returns(Operation $op): array
    {
        $funding=$op->receiptFunding();
        return [...(isset($funding['refund'])?[$funding['refund']]:[]),...($funding['late_credits']??[])];
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
        if(!is_array($return)||!is_array($payments=$return['payments']??null)){return null;}
        foreach($payments as $p){if(!is_array($p)||!is_int($p['id']??null)||!is_int($p['amount_minor']??null)){return null;}}
        if(($return['to']??null)==='original_payment'&&self::bridge($return)===null){return null;}
        try{return PricingRule::minor($return['amount']??null);}catch(\Throwable){return null;}
    }
    /** Shape check for retained complimentary receipt credits. Settlement still needs the native receipt and credit rows. */
    public static function complimentaryCredits(Operation $op): ?array
    {
        $funding = $op->funding ?? [];
        if (!array_key_exists('late_credits', $funding)) { return ['credits' => [], 'payments' => []]; }
        $history = $funding['late_credits'];
        if ($op->funding_basis !== 'complimentary' || !in_array($op->action, ['register', 'transfer', 'renew'], true)
            || $op->invoice_id === null || $op->invoice_item_id === null
            || !is_array($history) || $history === []) { return null; }
        return self::accountCredits($history);
    }
    private static function accountCredits(array $history): ?array
    {
        if (!array_is_list($history)) { return null; }
        $credits = $payments = [];
        foreach ($history as $entry) {
            $amount = self::amount($entry);
            if ($amount === null || $amount <= 0 || PricingRule::decimal($amount) !== $entry['amount']
                || ($entry['to'] ?? null) !== 'account_credit' || ($entry['currency'] ?? null) !== 'USD'
                || array_diff(array_keys($entry), ['payments', 'amount', 'currency', 'to', 'credit_id', 'at'])
                || !is_int($entry['credit_id'] ?? null) || $entry['credit_id'] <= 0
                || !is_string($entry['at'] ?? null) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/D', $entry['at'])
                || !array_is_list($entry['payments'])) { return null; }
            $credits[$entry['credit_id']] = true;
            $allocated = [];
            foreach ($entry['payments'] as $payment) {
                $id = $payment['id']; $minor = $payment['amount_minor'];
                if ($id <= 0 || $minor <= 0 || isset($allocated[$id]) || array_diff(array_keys($payment), ['id', 'amount_minor'])) { return null; }
                $allocated[$id] = $minor;
                $total = ($payments[$id] ?? 0) + $minor;
                if (!is_int($total)) { return null; }
                $payments[$id] = $total;
            }
            if (array_sum($allocated) !== $amount) { return null; }
        }
        return ['credits' => array_keys($credits), 'payments' => $payments];
    }
    /** Corroborate the ownership and payment identities of incremental returns on a dedicated closed invoice. */
    public static function requireClosedHistory(Operation $op, Invoice $invoice, array $money, ?Credit $credit): void
    {
        $funding = $op->funding ?? [];
        $history = $funding['late_credits'] ?? [];
        $results = $funding['receipt_return_results'] ?? [];
        if (!is_array($history) || !array_is_list($history) || !is_array($results)) {
            throw new \DomainException('Closed receipt return history requires review.');
        }
        $cash = $allocations = $releasedWallets = [];
        foreach ($history as $plan) {
            if (($plan['to'] ?? null) === 'account_credit') { $cash[] = $plan; continue; }
            $minor = self::amount($plan);
            if (($plan['to'] ?? null) !== 'original_payment' || ($plan['currency'] ?? null) !== 'USD'
                || $minor === null || $minor <= 0 || self::bridge($plan) !== 0) {
                throw new \DomainException('Unknown closed receipt return.');
            }
            $payments = array_column($plan['payments'], 'amount_minor', 'id');
            foreach ($plan['allocations'] as $a) {
                $id = $a['transaction_id'];
                if (isset($allocations[$a['key']]) || ($payments[$id] ?? null) !== $a['amount_minor']) {
                    throw new \DomainException('Closed receipt allocation changed.');
                }
                unset($payments[$id]);
                $allocations[$a['key']] = true;
                $result = $results[$a['key']] ?? [];
                if (array_key_exists($a['key'], $results) && !self::validResult($result, $a['amount_minor'])) {
                    throw new \DomainException('Closed receipt return outcome requires review.');
                }
                if (($result['state'] ?? null) === 'released' && ($result['credit_minor'] ?? 0) > 0) {
                    $releasedWallets[] = $result['credit_id'] ?? null;
                }
            }
            if ($payments || count($plan['payments']) !== count($plan['allocations'])) {
                throw new \DomainException('Closed receipt allocation is incomplete.');
            }
        }
        $account = self::accountCredits($cash);
        if ($account === null || array_diff_key($results, $allocations)) {
            throw new \DomainException('Closed receipt return history requires review.');
        }
        foreach ($account['payments'] as $id => $minor) {
            if ($minor > ($money['receipts'][$id]['gross'] ?? 0)) {
                throw new \DomainException('Closed receipt credit exceeds its payment.');
            }
        }
        if (!self::ownsCredits($invoice, [...$account['credits'], ...$releasedWallets], $credit)) {
            throw new \DomainException('Closed receipt credit owner changed.');
        }
    }
    public static function ownsCredits(Invoice $invoice, array $ids, ?Credit $credit): bool
    {
        if (array_filter($ids, fn ($id) => !is_int($id) || $id < 1)) { return false; }
        $ids = array_unique($ids);
        // The balance may have changed since the return. Only its identity corroborates the audit.
        if ($credit && (int) $credit->user_id === (int) $invoice->user_id && $credit->currency_code === $invoice->currency_code) {
            $ids = array_diff($ids, [(int) $credit->id]);
        }
        return !$ids || Credit::whereKey($ids)->where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)->count() === count($ids);
    }
    /** Any retained return, refund or late credit, that cannot be read stays with staff: callers check this before any
     * amount, allocation or coverage arithmetic. */
    public static function malformed(Operation $op): bool
    {
        foreach(self::returns($op) as $r){if(self::amount($r)===null){return true;}}
        return false;
    }
    /** A retained refund whose original payment or account-credit return has finished. An unreadable released credit
     * has not: it stays under review. */
    public static function settled(Operation $op): bool
    { try{return isset($op->funding['refund'])&&!self::malformed($op)&&self::outstanding($op)===0;}catch(\DomainException){return false;} }
    /** A released credit whose amount cannot be read. No retry can replace released evidence, so it stays under review. */
    public static function releasedUnreadable(Operation $op): bool
    { if(self::malformed($op)){return false;} try{self::outstanding($op);return false;}catch(\DomainException){return true;} }
    /** Pending allocations reserve funds; completed ones already have a signed native reversal from the gateway. */
    public static function outstanding(Operation $op): int
    {
        $minor=0;
        foreach(self::effective($op) as $p){foreach($p['allocations']??[] as $a){
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
        foreach(self::effective($op) as $p){foreach($p['allocations']??[] as $a){
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
        foreach(self::effective($op) as $p){foreach($p['allocations']??[] as $a){
            if(isset($a['gateway_key'])&&in_array($op->funding['receipt_return_results'][$a['key']]['state']??null,['refunded','credited'],true)){$ids[]=$a['transaction_id'];}
        }}
        return $transactions->whereNotIn('transaction_id',$keys)->whereNotIn('id',$ids);
    }
    public static function summary(Operation $op): ?array
    {
        $plans=self::plans($op);if(!$plans){return null;}$states=[];$bridge=0;
        if(self::malformed($op)){return self::REVIEW;}
        try{$money=InvoiceMoney::project((int)$op->invoice_id);}
        catch(\Throwable){return self::REVIEW;}
        if($money['overdrawn']||array_filter($money['receipts'],fn($r)=>$r['hold'])){return self::REVIEW;}
        try{$effective=self::effective($op);}catch(\DomainException){return self::REVIEW;} // An unreadable released credit.
        foreach($effective as $p){if(($p['to']??null)==='account_credit'){$bridge+=PricingRule::minor($p['amount']);}}
        foreach($effective as $p){if(($p['to']??null)!=='original_payment'){continue;}$bridge+=$p['bridge_minor'];foreach($p['allocations'] as $a){$entry=collect($money['ledger'])->first(fn($e)=>$e['gateway_id']===$a['gateway_id']&&$e['payment_intent_id']===$a['receipt']&&$e['allocation_key']===($a['gateway_key']??$a['key']));$states[]=$entry['state']??$op->funding['receipt_return_results'][$a['key']]['state']??'reserved';}}
        if(!$states&&$bridge===0){return null;}
        if(array_intersect($states,['review'])){return self::REVIEW;}
        if(array_diff($states,['refunded','credited'])){return ['Refund processing','We are returning your payment and checking its progress automatically.'];}
        // Words that say the return is account credit are wrong once a lost dispute removed credit from this order's receipt.
        if(($bridge>0||in_array('credited',$states,true))&&DisputeRecoveries::creditRemoved($op)){return self::REVIEW;}
        if(in_array('refunded',$states,true)&&($bridge>0||in_array('credited',$states,true))){return ['Money returned','Part was refunded to your original payment method. The rest is account credit because it could not be refunded.'];}
        return in_array('refunded',$states,true)?['Refunded','Your payment was refunded to your original payment method.']:['Credit added','The payment could not be refunded. The returned amount is account credit.'];
    }
    /** Read-only candidates only. A saved result or gateway allocation keeps its original return authority. */
    public static function localAllocations(Operation $op, array $money): array
    {
        $local=[];
        foreach(self::plans($op) as $plan){foreach($plan['allocations'] as $a){
            if(isset($a['gateway_key'])||!empty($op->funding['receipt_return_results'][$a['key']])){continue;}
            $entry=collect($money['ledger'])->first(fn($r)=>$r['gateway_id']===$a['gateway_id']&&$r['payment_intent_id']===$a['receipt']&&$r['allocation_key']===$a['key']);
            if(!$entry){$local[]=$a;}
        }}
        return $local;
    }
    /** Runs in the caller's outer transaction. A caller holding HoldingLocks passes them, so no prefix is taken again after
     * its ledger read; alone, release takes them first. The ledger always wins over a stale local result. */
    public static function release(Operation $op,string $key,string $reason,?array $l=null): void
    {
        if($l===null){$d=$op->domain()->firstOrFail();$l=HoldingLocks::take($d,released:$d->reservation_name===null);}
        InvoiceScope::cover($l,(int)$op->invoice_id);
        $op=$l['operations']->firstWhere('id',$op->id)??throw new \DomainException('Missing receipt allocation.');
        $a=collect(self::plans($op))->flatMap(fn($p)=>$p['allocations'])->firstWhere('key',$key);
        if(!$a){throw new \DomainException('Missing receipt allocation.');}
        $known=$op->funding['receipt_return_results'][$key]??[];
        if($known){return;} // Sending and gateway-owned results can never be released.
        $money=$reason==='recovery'?InvoiceMoney::lockedRecord($l,(int)$op->invoice_id,Operation::where('invoice_id',$op->invoice_id)->orderBy('id')->lockForUpdate()->get(),$key):InvoiceMoney::load($l,(int)$op->invoice_id,$key);
        $entry=collect($money['ledger'])->first(fn($r)=>$r['gateway_id']===$a['gateway_id']&&$r['payment_intent_id']===$a['receipt']&&$r['allocation_key']===($a['gateway_key']??$key));
        if($entry){
            $result=[...$entry,'amount_minor'=>(int)$entry['amount_minor']];
            if(isset($entry['return_key'])){$result['key']=$entry['return_key'];}
        }else{
            if(isset($a['gateway_key'])||$reason!=='recovery'&&($money['ownerHold']||array_filter($money['receipts'],fn($r)=>$r['hold']))){return;}
            $minor=$reason==='recovery'?0:min($a['amount_minor'],array_sum(InvoiceMoney::heldFor($money,$a['transaction_id'])));
            $credit=$l['credit']??new Credit(['user_id'=>$l['user']->id,'currency_code'=>'USD','amount'=>'0.00']);
            if($minor>0){$credit->amount=PricingRule::decimal(PricingRule::minor($credit->amount)+$minor);$credit->save();}
            $result=['state'=>'released','amount_minor'=>$a['amount_minor'],'credit_minor'=>$minor,'credit_id'=>$credit->id,'reason'=>$reason,'at'=>now()->toAtomString()];
        }
        self::saveResult($op,$a,$result,$l);
    }
    private static function validResult(mixed $result, int $minor): bool
    {
        return is_array($result) && ($result['amount_minor'] ?? null) === $minor
            && in_array($result['state'] ?? null, ['reserved', 'sending', 'pending', 'review', 'refunded', 'credited', 'released'], true);
    }
    private static function saveResult(Operation $op,array $a,array $result,array $l): void
    {
        if (!self::validResult($result, $a['amount_minor'])) { throw new \DomainException('Gateway return outcome needs review.'); }
        $funding=[...$op->funding,'receipt_return_results'=>[...($op->funding['receipt_return_results']??[]),$a['key']=>$result]];
        $proof=clone $op;$proof->funding=$funding;self::completedMinor($proof,$l);$op->update(['funding'=>$funding]);
    }
    /** Each phase is one money decision on the order's invoice. Contention retries it once; a second defers it under the
     * caller's work kind, with no repair, and resume stops there: the queued work runs resume again. $deferred tells
     * the caller that a phase was queued, so a staff caller can say to try again. */
    public static function resume(int $id,\DateTimeImmutable $now,string $kind,?bool &$deferred=null): ?array
    {
        $deferred=false;OperationClaims::outside();$seen=Operation::findOrFail($id);$plans=self::plans($seen);
        if(!$plans){return null;}
        // An unbalanced plan stays with staff: no gateway call and no automatic resolution until it is reviewed.
        if(self::malformed($seen)){RefundRepairs::failed($id,$now->getTimestamp());return null;}
        $phase=static function(\Closure $decide)use($seen,$kind):MoneyResult {
            $d=$seen->domain()->firstOrFail();
            $r=MoneyDecisions::run(new MoneyRequest($d,$kind,(int)$seen->id,released:$d->reservation_name===null,scope:MoneyScope::Invoice,invoiceId:(int)$seen->invoice_id),
                static function(array $l)use($seen,$decide):mixed {
                    $op=$l['operations']->firstWhere('id',$seen->id)??throw new \DomainException('Missing receipt allocation.');
                    InvoiceScope::cover($l,(int)$op->invoice_id);return $decide($op,$l);
                });
            if($r->error){throw $r->error;}
            return $r;
        };
        try{
            $results=[];$repair=false;
            $allocations=collect($plans)->flatMap(fn($p)=>$p['allocations'])->all();
            usort($allocations,fn($a,$b)=>isset($seen->funding['receipt_return_results'][$b['key']])<=>isset($seen->funding['receipt_return_results'][$a['key']]));
            foreach($allocations as $a){
                $known=$seen->funding['receipt_return_results'][$a['key']]??null;
                if(in_array($known['state']??null,['credited','released'],true)){$results[$a['key']]=$known;continue;}
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
                if(!isset($a['gateway_key'])&&$result===null&&($known===null||($known['state']??null)==='sending')){
                    if($known===null){
                        $send=$phase(function(Operation $op,array $l)use($a,$now):array {
                            if(isset($op->funding['receipt_return_results'][$a['key']])){return [false,false];}
                            $money=InvoiceMoney::load($l,(int)$op->invoice_id,$a['key']);
                            if(InvoiceMoney::heldFor($money,$a['transaction_id'])['card']<$a['amount_minor']){self::release($op,$a['key'],'receipt_changed',$l);return [false,$money['overdrawn']];}
                            self::saveResult($op,$a,['state'=>'sending','amount_minor'=>$a['amount_minor'],'at'=>$now->format(DATE_ATOM)],$l);return [true,false];
                        });
                        if($send->outcome===MoneyOutcome::Deferred){$deferred=true;return null;}
                        $repair=$repair||$send->value[1];
                        if(!$send->value[0]){continue;}
                    }
                    $result=ExtensionHelper::call($row->gateway,'allocateReceiptReturn',[$invoice,$a['receipt'],$a['key'],$a['amount_minor']]);
                }
                $result??=[...($known??[]),'state'=>'review','amount_minor'=>$a['amount_minor'],'reason'=>'Retained gateway return is missing. Review the original payment.'];
                if(!is_array($result)||($result['amount_minor']??null)!==$a['amount_minor']
                    ||!in_array($result['state']??null,['reserved','pending','refunded','credited','review'],true)){
                    throw new \DomainException('Gateway return outcome needs review.');
                }
                if(in_array($result['state'],['refunded','credited'],true)&&(!is_string($result['key']??null)||$result['key']==='')){throw new \DomainException('Completed return reversal identity is missing.');}
                $result=array_intersect_key($result,array_flip(['state','amount_minor','refund_id','reason','key']));
                // Deferred, the gateway's answer is read again on the next run under the same key.
                $saved=$phase(function(Operation $op,array $l)use($plans,$a,$known,$result):?bool {
                    if(self::plans($op)!==$plans){throw new \DomainException('Receipt return plan changed.');}
                    $saved=$op->funding['receipt_return_results'][$a['key']]??null;
                    if($saved!==$known&&($saved['state']??null)!=='sending'){return null;}
                    self::saveResult($op,$a,$result,$l);return true;
                });
                if($saved->outcome===MoneyOutcome::Deferred){$deferred=true;return null;}
            }
            $fresh=$seen->fresh();$results=$fresh->funding['receipt_return_results']??[];
            $complete=self::outstanding($fresh)===0;
            if($complete){
                $checked=$phase(fn(Operation $op,array $l):bool=>InvoiceMoney::load($l,(int)$fresh->invoice_id)['overdrawn']);
                if($checked->outcome===MoneyOutcome::Deferred){$deferred=true;return null;}
                $repair=$checked->value;
            }
            if(!$complete||$repair){RefundRepairs::failed($id,$now->getTimestamp());return null;}
            RefundRepairs::resolved($id,$now->getTimestamp());
            $new=array_filter($results,fn($r,$key)=>!in_array($seen->funding['receipt_return_results'][$key]['state']??null,['refunded','credited','released'],true),ARRAY_FILTER_USE_BOTH);
            $minor=array_sum(array_map(fn($r)=>$r['state']==='released'?$r['credit_minor']:$r['amount_minor'],$new));
            return $minor>0?['amount'=>PricingRule::decimal($minor),'currency'=>'USD']:null;
        }catch(\Throwable $e){RefundRepairs::failed($id,$now->getTimestamp());throw $e;}
    }
}
