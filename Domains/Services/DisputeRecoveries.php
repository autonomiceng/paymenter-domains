<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway,Setting};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Lost bank disputes remove only unused returned credit. Fixed alerts retain the rest for staff. */
final readonly class DisputeRecoveries
{
    public function __construct(private Clock $clock) {}
    public static function key(string $code,string $dispute): string { return hash('sha256',$code.':'.$dispute); }
    private static function cursor()
    { return Setting::where('settingable_type',self::class)->where('settingable_id',0)->where('key','domains_dispute_scan'); }
    private static function advance(array $cursor): void
    { Setting::updateOrCreate(['settingable_type'=>self::class,'settingable_id'=>0,'key'=>'domains_dispute_scan'],['value'=>json_encode($cursor,JSON_THROW_ON_ERROR),'type'=>'string','encrypted'=>false]); }
    public function sweep(): void
    {
        OperationClaims::outside();
        foreach(DB::table('domains_obligation_alerts')->where('code','dispute_recovery')->whereNull('resolved_at')->orderBy('first_seen')->orderBy('id')->limit(25)->get() as $alert){
            try{
                $op=Operation::findOrFail($alert->operation_id);
                $record=InvoiceMoney::project((int)$op->invoice_id);
                foreach($record['ledger'] as $entry){
                    $id=self::dispute($entry);
                    if($id!==null&&hash_equals($alert->alert_key,self::key('dispute_recovery',$id))){$this->recover($entry);break;}
                }
            }catch(\Throwable){ /* Keep the retry record open; discovery has its own budget. */ }
        }
        $gateway=Gateway::where('extension','AeStripe')->orderBy('id')->first();
        if(!$gateway||!ExtensionHelper::hasFunction($gateway,'lostDisputes')){return;}
        $cursor=json_decode(self::cursor()->value('value')??'{"at":0,"key":""}',true,8,JSON_THROW_ON_ERROR);
        for($page=0;$page<10;$page++){
            $rows=ExtensionHelper::call($gateway,'lostDisputes',[(int)$cursor['at'],(string)$cursor['key'],100]);
            if(!is_array($rows)){throw new \DomainException('Unreadable lost disputes.');}
            foreach($rows as $row){
                $row=(array)$row;
                $next=['at'=>(int)$row['updated_at'],'key'=>$row['payment_intent_id'].':'.$row['allocation_key']];
                $this->recover($row,$next);$cursor=$next;
            }
            if(count($rows)<100){break;}
        }
    }
    public static function dispute(array $entry): ?string
    {
        return ($entry['state']??null)==='lost'&&preg_match('/^stripe:dispute:(.+)$/D',$entry['allocation_key']??'',$m)?$m[1]:null;
    }
    public function recover(array $entry,?array $cursor=null): void
    {
        OperationClaims::outside();$id=self::dispute($entry)??throw new \DomainException('Lost dispute required.');
        $op=Operation::where('invoice_id',$entry['invoice_id'])->orderBy('id')->first();
        if(!$op){if($cursor!==null){self::advance($cursor);}return;}
        $d=$op->domain()->firstOrFail();
        $result=MoneyDecisions::run(new MoneyRequest($d,released:$d->reservation_name===null,scope:MoneyScope::Invoice,invoiceId:(int)$op->invoice_id,retryRecord:'dispute_recovery'),
            function(array $l)use($op,$entry,$cursor):bool {
                self::locked($l,(int)$op->invoice_id,$entry,$this->clock->now());
                if($cursor!==null){self::advance($cursor);}return true;
            });
        if(in_array($result->outcome,[MoneyOutcome::Deferred,MoneyOutcome::Failed],true)){
            // This transaction is the durable retry contract declared above. Failure stops discovery before its cursor moves.
            DB::transaction(function()use($op,$id,$cursor):void {
                self::alert($op,'account_money_hold',$id,$this->clock->now()->getTimestamp());
                self::alert($op,'dispute_recovery',$id,$this->clock->now()->getTimestamp());
                if($cursor!==null){self::advance($cursor);}
            });
            return;
        }
        app(Reminders::class)->remind((int)$op->domain_id);
    }
    private static function alert(Operation $op,string $code,string $id,int $now): void
    {
        $key=self::key($code,$id);
        DB::table('domains_obligation_alerts')->insertOrIgnore(['alert_key'=>$key,'domain_id'=>$op->domain_id,'generation'=>$op->authority_generation,
            'operation_id'=>$op->id,'code'=>$code,'first_seen'=>$now,'last_seen'=>$now]);
        if($code==='dispute_recovery'){DB::table('domains_obligation_alerts')->where('alert_key',$key)->update(['resolved_at'=>null,'last_seen'=>$now]);}
    }
    /** Reuses the caller's locks. No runner, remote sends or notices; load() uses this for a newly observed owed loss. */
    public static function locked(array $l,int $invoiceId,array $entry,\DateTimeImmutable $now,?array $money=null): void
    {
        if(DB::transactionLevel()<1||($l['advisory']??true)){throw new \LogicException('Dispute recovery requires holding locks.');}
        InvoiceScope::cover($l,$invoiceId);
        $id=self::dispute($entry)??throw new \DomainException('Lost dispute required.');
        $peers=Operation::where('invoice_id',$invoiceId)->orderBy('id')->lockForUpdate()->get();$op=$peers->firstOrFail();
        $invoice=$l['invoices']->firstWhere('id',$invoiceId);
        if(!$invoice||(int)$invoice->user_id!==(int)$entry['user_id']||strtoupper($entry['currency'])!==$invoice->currency_code){throw new \DomainException('Dispute invoice identity changed.');}
        $money??=InvoiceMoney::lockedRecord($l,$invoiceId,$peers);
        $lost=collect($money['ledger'])->first(fn($e)=>self::dispute($e)===$id&&$e['payment_intent_id']===$entry['payment_intent_id']);
        if(!$lost||(int)$lost['amount_minor']!==(int)$entry['amount_minor']){throw new \DomainException('Lost dispute evidence changed.');}
        self::alert($op,'account_money_hold',$id,$now->getTimestamp());
        $receiptId=null;
        foreach($money['receipts'] as $pid=>$r){if($r['receipt']===$entry['payment_intent_id']&&$r['gateway_id']===$lost['gateway_id']){$receiptId=$pid;break;}}
        if($receiptId===null){throw new \DomainException('Dispute receipt missing.');}
        $r=$money['receipts'][$receiptId];
        $recorded=0;
        foreach($peers as $peer){foreach($peer->funding['dispute_holds']??[] as $hold){if($hold['dispute_id']===$id){$recorded+=$hold['owed_minor'];}}}
        // Only completed returns and credit count; reserved and pending allocations never create recovery debt.
        $owed=min((int)$entry['amount_minor'],max(0,$r['completed']+$r['credit']-$r['recovered']+$r['external']-$r['gross']));
        $left=max(0,$owed-$recorded);$credit=$l['credit'];$unused=$credit?PricingRule::minor($credit->amount):0;
        foreach($peers as $peer){
            $f=$peer->funding??[];$share=0;
            foreach(ReceiptReturns::effective($peer) as $plan){
                $split=[];
                foreach($plan['payments'] as $p){if($p['amount_minor']>0){$split[$p['id']]=($split[$p['id']]??0)+$p['amount_minor'];}}
                foreach($plan['allocations']??[] as $a){
                    $split[$a['transaction_id']]=($split[$a['transaction_id']]??0)-$a['amount_minor'];
                    if($a['transaction_id']!==$receiptId){continue;}
                    $e=collect($money['ledger'])->first(fn($e)=>$e['gateway_id']===$a['gateway_id']&&$e['payment_intent_id']===$a['receipt']&&$e['allocation_key']===($a['gateway_key']??$a['key']));
                    $state=$e['state']??$f['receipt_return_results'][$a['key']]['state']??'reserved';
                    if(in_array($state,['refunded','credited'],true)){$share+=$a['amount_minor'];}
                    elseif($state==='reserved'&&!$e&&!isset($f['receipt_return_results'][$a['key']])){
                        ReceiptReturns::release($peer,$a['key'],'recovery',[...$l,'operations'=>$peers]);
                        $peer->refresh();$f=$peer->funding;
                    }
                }
                $minor=($plan['to']??null)==='original_payment'?ReceiptReturns::bridge($plan):PricingRule::minor($plan['amount']);
                $split=array_filter($split,fn($v)=>$v>0);
                if(count($split)===1&&reset($split)>=$minor){$split=[array_key_first($split)=>$minor];}
                if(array_sum($split)===$minor){$share+=$split[$receiptId]??0;}
            }
            $prior=0;foreach($f['dispute_holds']??[] as $hold){if($hold['dispute_id']===$id){$prior+=$hold['owed_minor'];}}
            $amount=min($left,max(0,$share-$prior));if($amount===0){continue;}
            $removed=min($amount,$unused);$unused-=$removed;$left-=$amount;
            $hold=['dispute_id'=>$id,'payment_id'=>$receiptId,'lost_minor'=>(int)$entry['amount_minor'],'owed_minor'=>$amount,
                'removed_minor'=>$removed,'remainder_minor'=>$amount-$removed,'credit_id'=>$credit?->id,'at'=>$now->format(DATE_ATOM)];
            $peer->update(['funding'=>[...$f,'dispute_holds'=>[...($f['dispute_holds']??[]),$hold]]]);
        }
        if($left>0){throw new \DomainException('Returned dispute shares need review.');}
        if($credit&&$unused!==PricingRule::minor($credit->amount)){$credit->update(['amount'=>PricingRule::decimal($unused)]);}
        DB::table('domains_obligation_alerts')->where('alert_key',self::key('dispute_recovery',$id))->whereNull('resolved_at')->update(['resolved_at'=>$now->getTimestamp()]);
    }
}
