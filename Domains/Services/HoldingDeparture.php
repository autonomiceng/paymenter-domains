<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,InvoiceTransaction,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Staff confirm a suspected departure once. One full money decision closes unpaid renewals, cancels the service and
 * freezes the departure record; any blocking line rolls all of it back. No provider call or notification runs inside. */
final class HoldingDeparture
{
    public const PERMISSION='admin.domains.holdings.depart';
    public const ACTION='depart';
    public const REASONS=['other_registrar'=>'Moved to another registrar.','other_account'=>'Moved to another account at the same registrar.','deleted'=>'Deleted at the registry.'];
    /** A definite absence read older than this needs Check again first. */
    public const FRESH=86400;
    /** Blocking lines in check order (mock 06c). */
    public const UNREADABLE=1, OWNER_HOLD=2, DISPUTE=3, OVERDRAWN=4, RETURN_SENDING=5, PAYMENT_PROCESSING=6, AT_REGISTRAR=7, RENEWAL_REVIEW=8, STALE=9;
    private const OPEN=['awaiting_payment','queued','held'], TERMINAL=['rejected','disposed','cancelled'];
    public function __construct(private readonly Clock $clock){}

    /** Read-only preview for the staff page: advisory rows and the shared money projection. Never load, recovery or a writer.
     * @return array{block:?array,close:list<int>,kept:list<int>} */
    public static function preview(Domain $d,int $now): array
    {
        try{
            $l=HoldingLocks::take($d,advisory:true);
            $ids=self::renewals($l)->pluck('invoice_id')->filter()->map(fn($id)=>(int)$id)->unique()->values()->all();
            $money=InvoiceMoney::projectBatch(Invoice::whereIn('id',$ids)->orderBy('id')->get(),
                InvoiceTransaction::with('gateway')->whereIn('invoice_id',$ids)->orderBy('id')->get(),InvoiceMoney::peers($ids));
            $a=self::assess($l,fn(int $id)=>$money[$id]??null,$now,InvoiceMoney::ownerHeld((int)$d->user_id));
        }catch(\Throwable){return ['block'=>['line'=>self::UNREADABLE,'invoice'=>null,'operation'=>null,'hold_domain'=>null],'close'=>[],'kept'=>[]];}
        return ['block'=>$a['block'],'close'=>array_map(fn($op)=>(int)$op->id,$a['close']),'kept'=>array_map(fn($op)=>(int)$op->id,$a['kept'])];
    }

    /** Applied with the frozen record; Failed carries DepartureBlocked or a review refusal; Deferred is MoneyDecisions::BUSY. */
    public function confirm(User $actor,int $domainId,string $ticket,string $reason,string $note): MoneyResult
    {
        OperationClaims::outside();$seed=Domain::findOrFail($domainId);
        return MoneyDecisions::run(new MoneyRequest($seed,staffId:(int)$actor->id,scope:MoneyScope::Full),function(array $l)use($actor,$ticket,$reason,$note):array{
            $d=$l['domain'];$staff=$l['staff'];
            if(!$staff||!$staff->hasPermission('admin.domains.view')||!$staff->hasPermission('admin.domains.manage')||!$staff->hasPermission(self::PERMISSION)){
                throw new StaffReviewRefused('Current staff permissions required. Reload this page.');}
            if(!isset(self::REASONS[$reason])){throw new StaffReviewRefused('Choose a reason.');}
            StaffReason::require($note);
            [$t,$history]=StaffHoldingReview::verify($l,(int)$actor->id,self::ACTION,$ticket,$this->clock);
            if(!DepartureChecks::suspected($d)){throw new \DomainException('Holding changed. Reload this page and review it again.');}
            $now=$this->clock->now();$records=[];
            // Locked authority. An unreadable record is line 1; any block below rolls back what a load wrote.
            $money=function(int $id)use($l,&$records):?array{
                if(array_key_exists($id,$records)){return $records[$id];}
                try{return $records[$id]=InvoiceMoney::load($l,$id);}catch(\DomainException $e){if($e->getMessage()==='Invoice owner changed.'){throw $e;}return $records[$id]=null;}
            };
            $a=self::assess($l,$money,$now->getTimestamp(),InvoiceMoney::ownerHeld((int)$l['user']->id,true));
            if($a['block']!==null){throw new DepartureBlocked($a['block']);}
            $dns=DepartureChecks::dnsCase($d);$absence=self::absenceAt($d);$outcomes=[];
            foreach($a['close'] as $op){
                RenewalCycles::retireDeparted($op,$l,self::REASONS[$reason],$this->clock);
                $outcomes[]=['operation_id'=>(int)$op->id,'invoice_id'=>(int)$op->invoice_id,'outcome'=>'cancelled_unpaid'];
            }
            foreach($a['kept'] as $op){$outcomes[]=['operation_id'=>(int)$op->id,'invoice_id'=>(int)$op->invoice_id,'outcome'=>'kept'];}
            // Strict settlement of every closed invoice from fresh locked rows, never the earlier read.
            foreach(array_unique(array_map(fn($op)=>(int)$op->invoice_id,$a['close'])) as $id){
                $r=InvoiceMoney::lockedRecord($l,$id,Operation::where('invoice_id',$id)->orderBy('id')->lockForUpdate()->get());
                if(!InvoiceMoney::settled($r)||$r['ownerHold']){throw new DepartureBlocked(['line'=>self::RENEWAL_REVIEW,'invoice'=>$id,'operation'=>null,'hold_domain'=>null]);}
            }
            $record=['identity'=>DepartureProof::identity($d),'reason'=>$reason,'note'=>$note,'actor_id'=>(int)$staff->id,'confirmed_at'=>$now->format(DATE_ATOM),
                'absence'=>['identity'=>ObservationRecords::identity($d),'at'=>$absence],'registry'=>$d->observation['departure_check']['registry']??null,
                'dns_case'=>$dns,'invoices'=>$outcomes,'credit_minor'=>0];
            $history[]=['key'=>$t['key'],'actor_id'=>(int)$staff->id,'action'=>self::ACTION,'reason'=>$note,'at'=>$now->format(DATE_ATOM),
                'before'=>['status'=>$d->status],'after'=>['status'=>'departed']];
            $d->update(['status'=>'departed','renewal_policy'=>[...($d->renewal_policy??[]),'departure'=>$record,'staff_holdings'=>$history]]);
            NativeDomainGuard::departed($l);
            // Only this holding's departure review; money, dispute and loss alerts stay open.
            DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->update(['resolved_at'=>$now->getTimestamp()]);
            return $record;
        });
    }

    /** First blocking line in check order, plus the renewals confirmation closes and the completed ones it keeps.
     * @param \Closure(int):?array $money a locked load or a projection; null means unreadable
     * @return array{block:?array,close:list<Operation>,kept:list<Operation>} */
    private static function assess(array $l,\Closure $money,int $now,bool $ownerHeld): array
    {
        $d=$l['domain'];$blocks=[];$close=[];$kept=[];
        $block=static function(int $line,?int $invoice=null,?int $op=null)use(&$blocks,$d):void{
            $blocks[]=['line'=>$line,'invoice'=>$invoice,'operation'=>$op,'hold_domain'=>in_array($line,[self::OWNER_HOLD,self::OVERDRAWN],true)?self::holdDomain((int)$d->user_id):null];
        };
        if($ownerHeld){$block(self::OWNER_HOLD);}
        foreach(self::renewals($l) as $op){
            $invoice=$op->invoice_id===null?null:(int)$op->invoice_id;
            $r=$invoice===null?null:$money($invoice);
            if($invoice!==null&&$r===null){$block(self::UNREADABLE,$invoice);continue;}
            if($r!==null){
                if($r['ownerHold']){$block(self::OWNER_HOLD,$invoice);}
                if(array_filter($r['receipts'],fn($x)=>$x['hold'])){$block(self::DISPUTE,$invoice);}
                if($r['overdrawn']){$block(self::OVERDRAWN,$invoice);}
                if(array_sum(array_column($r['receipts'],'reserved'))>0){$block(self::RETURN_SENDING,$invoice);}
                if($r['pending']){$block(self::PAYMENT_PROCESSING,$invoice);}
            }
            $generation=$op->authority_generation===$d->authority_generation&&(int)$op->service_id===(int)$d->service_id;
            if($op->state==='succeeded'){$kept[]=$op;continue;}
            if(!in_array($op->state,[...self::OPEN,...self::TERMINAL],true)){$block(self::AT_REGISTRAR,$invoice,(int)$op->id);continue;}
            if(in_array($op->state,self::OPEN,true)&&$generation&&RenewalCycles::canRetireUnpaid($op,$l)){$close[]=$op;continue;}
            // Closed earlier with nothing left on its invoice: history, not part of this closure.
            if(in_array($op->state,self::TERMINAL,true)&&$r!==null&&InvoiceMoney::settled($r)){continue;}
            if(in_array($op->state,self::TERMINAL,true)&&$invoice===null){continue;}
            $block(self::RENEWAL_REVIEW,$invoice,(int)$op->id);
        }
        // Acquisitions keep their completed history; one that never finished is not a departure.
        foreach($l['operations']->whereIn('action',['register','transfer']) as $op){
            if($op->state!=='succeeded'){$block(self::RENEWAL_REVIEW,$op->invoice_id===null?null:(int)$op->invoice_id,(int)$op->id);}
        }
        $at=self::absenceAt($d);
        if($at===null||$now-$at>self::FRESH){$block(self::STALE);}
        usort($blocks,fn($a,$b)=>$a['line']<=>$b['line']);
        return ['block'=>$blocks[0]??null,'close'=>$close,'kept'=>$kept];
    }
    private static function renewals(array $l): \Illuminate\Support\Collection
    { return $l['operations']->where('action','renew')->sortBy('id')->values(); }
    /** Time of the identity-bound definite absence read, or null when unproved. */
    public static function absenceAt(Domain $d): ?int
    {
        $time=static function(mixed $v): ?int { try { return is_string($v)?(new \DateTimeImmutable($v))->getTimestamp():(is_int($v)?$v:null); } catch (\Throwable) { return null; } };
        $proof=$d->observation['renewal_ownership']??null;
        if(is_array($proof)&&($proof['identity']??null)===ObservationRecords::identity($d)&&($proof['owned']??null)===false){return $time($proof['at']??null);}
        $failure=$d->observation['record_failure']??null;
        return is_array($failure)&&($failure['identity']??null)===ObservationRecords::identity($d)&&($failure['code']??null)==='domain_not_in_account'?$time($failure['at']??null):null;
    }
    /** The domain whose open account hold blocks this owner, for the staff link. */
    private static function holdDomain(int $userId): ?int
    {
        $id=DB::table('domains_obligation_alerts')->join('domains_domains','domains_domains.id','=','domains_obligation_alerts.domain_id')
            ->where('domains_domains.user_id',$userId)->where('code','account_money_hold')->whereNull('resolved_at')
            ->orderBy('domains_obligation_alerts.id')->value('domains_obligation_alerts.domain_id');
        return $id===null?null:(int)$id;
    }
}
