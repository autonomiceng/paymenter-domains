<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use App\Models\{Invoice,InvoiceTransaction,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Staff confirm a suspected departure once. One full money decision closes unpaid renewals, returns paid unsent renewals
 * as account credit, cancels the service and freezes the departure record; any blocking line rolls all of it back. No
 * provider call runs inside; the customer email is reserved inside and handed off once after commit. */
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
     * @return array{block:?array,close:list<int>,credit:list<int>,kept:list<int>,credit_minor:array<int,int>} */
    public static function preview(Domain $d,int $now): array
    {
        try{
            $l=HoldingLocks::take($d,advisory:true);
            $ids=self::invoiceIds($l);
            $money=InvoiceMoney::projectBatch(Invoice::whereIn('id',$ids)->orderBy('id')->get(),
                InvoiceTransaction::with('gateway')->whereIn('invoice_id',$ids)->orderBy('id')->get(),InvoiceMoney::peers($ids));
            $a=self::assess($l,fn(int $id)=>$money[$id]??null,$now,InvoiceMoney::ownerHeld((int)$d->user_id));
            // The account credit each credited invoice would return: its free pool plus local returns released back to it,
            // as confirmation counts them.
            $amounts=[];
            foreach($a['credit'] as $op){
                $id=(int)$op->invoice_id;$amounts[$id]??=InvoiceMoney::held($money[$id]);
                if($op->state==='rejected'){foreach(ReceiptReturns::localAllocations($op,$money[$id]) as $x){$amounts[$id]+=$x['amount_minor'];}}
            }
        }catch(\Throwable){return ['block'=>['line'=>self::UNREADABLE,'invoice'=>null,'operation'=>null,'hold_domain'=>null],'close'=>[],'credit'=>[],'kept'=>[],'credit_minor'=>[]];}
        $ids=fn(array $ops)=>array_map(fn($op)=>(int)$op->id,$ops);
        return ['block'=>$a['block'],'close'=>$ids($a['close']),'credit'=>$ids($a['credit']),'kept'=>$ids($a['kept']),'credit_minor'=>$amounts];
    }

    /** Applied with the frozen record; Failed carries DepartureBlocked or a review refusal; Deferred is MoneyDecisions::BUSY. */
    public function confirm(User $actor,int $domainId,string $ticket,string $reason,string $note): MoneyResult
    {
        OperationClaims::outside();$seed=Domain::findOrFail($domainId);$claim=null;
        $result=MoneyDecisions::run(new MoneyRequest($seed,staffId:(int)$actor->id,scope:MoneyScope::Full),function(array $l)use($actor,$ticket,$reason,$note,&$claim):array{
            $claim=null; // A retried attempt reserves again; the rolled-back reservation is gone.
            $d=$l['domain'];$staff=$l['staff'];
            if(!$staff||!$staff->hasPermission('admin.domains.view')||!$staff->hasPermission('admin.domains.manage')||!$staff->hasPermission(self::PERMISSION)){
                throw new StaffReviewRefused('Current staff permissions required. Reload this page.');}
            if(!isset(self::REASONS[$reason])){throw new StaffReviewRefused('Choose a reason.');}
            StaffReason::require($note);
            [$t,$history]=StaffHoldingReview::verify($l,(int)$actor->id,self::ACTION,$ticket,$this->clock);
            if(!DepartureChecks::suspected($d)){throw new \DomainException('Holding changed. Reload this page and review it again.');}
            $now=$this->clock->now();$records=[];
            // Every affected invoice's peers in invoice id order before the first receipt-ledger read; later loads reuse them.
            foreach(self::invoiceIds($l) as $id){Operation::where('invoice_id',$id)->orderBy('id')->lockForUpdate()->get();}
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
            // Paid but never sent: close it unsent with its retained receipts, then return every free share as account
            // credit. The free pool read before these writes must equal the new credit exactly.
            $free=0;$creditBefore=self::creditMinor($l);$credited=0;
            foreach(array_unique(array_map(fn($op)=>(int)$op->invoice_id,$a['credit'])) as $id){$free+=InvoiceMoney::held($money($id)??throw new DepartureBlocked(['line'=>self::UNREADABLE,'invoice'=>$id,'operation'=>null,'hold_domain'=>null]));}
            foreach($a['credit'] as $op){
                $receipts=$l['transactions']->where('invoice_id',$op->invoice_id)->where('status',InvoiceTransactionStatus::Succeeded)->pluck('id')->map(fn($id)=>(int)$id)->values()->all();
                if($op->state!=='rejected'){FundsWaits::closeRenewal($op,$l,$now,$receipts);}
                $released=0;$minor=app(Refunds::class)->departLocked($l,$op,$released);$free+=$released;$credited+=$minor;
                $outcomes[]=['operation_id'=>(int)$op->id,'invoice_id'=>(int)$op->invoice_id,'outcome'=>'credited','credit_minor'=>$minor];
            }
            if($free!==$credited||self::creditMinor($l)-$creditBefore!==$credited){
                throw new DepartureBlocked(['line'=>self::RENEWAL_REVIEW,'invoice'=>$a['credit']?(int)$a['credit'][0]->invoice_id:null,'operation'=>null,'hold_domain'=>null]);
            }
            foreach($a['kept'] as $op){$outcomes[]=['operation_id'=>(int)$op->id,'invoice_id'=>(int)$op->invoice_id,'outcome'=>'kept'];}
            // Strict settlement of every closed invoice from fresh locked rows, never the earlier read.
            foreach(array_unique(array_map(fn($op)=>(int)$op->invoice_id,[...$a['close'],...$a['credit']])) as $id){
                $r=InvoiceMoney::lockedRecord($l,$id,Operation::where('invoice_id',$id)->orderBy('id')->lockForUpdate()->get());
                if(!InvoiceMoney::settled($r)||$r['ownerHold']){throw new DepartureBlocked(['line'=>self::RENEWAL_REVIEW,'invoice'=>$id,'operation'=>null,'hold_domain'=>null]);}
            }
            $record=['identity'=>DepartureProof::identity($d),'reason'=>$reason,'note'=>$note,'actor_id'=>(int)$staff->id,'confirmed_at'=>$now->format(DATE_ATOM),
                'absence'=>['identity'=>ObservationRecords::identity($d),'at'=>$absence],'registry'=>$d->observation['departure_check']['registry']??null,
                'dns_case'=>$dns,'invoices'=>$outcomes,'credit_minor'=>$credited];
            $history[]=['key'=>$t['key'],'actor_id'=>(int)$staff->id,'action'=>self::ACTION,'reason'=>$note,'at'=>$now->format(DATE_ATOM),
                'before'=>['status'=>$d->status],'after'=>['status'=>'departed']];
            $d->update(['status'=>'departed','renewal_policy'=>[...($d->renewal_policy??[]),'departure'=>$record,'staff_holdings'=>$history]]);
            NativeDomainGuard::departed($l);
            DomainReservations::releaseDeparted($l);
            $watch=app(WatchDiscovery::class)->departed($d,$now->getTimestamp());
            // The customer email is reserved here and handed off only after commit, so a rollback sends nothing.
            $email=self::notice($l,$record,$watch,$now,$claim);
            // The frozen record is written once; the watch outcome and the email join this confirmation's history entry.
            if($watch!==null){$history[array_key_last($history)]['watch']=$watch;}
            if($email!==null){$history[array_key_last($history)]['email']=$email;}
            if($watch!==null||$email!==null){$d->update(['renewal_policy'=>[...$d->renewal_policy,'staff_holdings'=>$history]]);}
            // Only this holding's departure review; money, dispute and loss alerts stay open.
            DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->update(['resolved_at'=>$now->getTimestamp()]);
            return [...$record,'watch'=>$watch];
        });
        // Monitoring work, discovery hints and the email start only after the decision committed.
        if($result->outcome===MoneyOutcome::Applied){
            $at=$this->clock->now()->getTimestamp();
            if($claim&&!ReminderDelivery::handoff($claim,$at)){self::alert($domainId,'delivery_uncertain',$at);}
            WatchDiscoveryHints::names([[(int)$seed->user_id,(string)$seed->domain]]);
            if(($result->value['watch']['state']??null)==='active'){app(WatchWork::class)->enrollWatch((int)$result->value['watch']['id'],(int)$seed->user_id);}
        }
        return $result;
    }

    /** Reserves the one departure email (mocks 08, 08b, 08c) under the decision's locks. Returns the Email sent fact for
     * the history entry: delivery key, subject and recipient. A template or channel failure leaves the staff alert and
     * never blocks the confirmation; database errors pass through to the decision. */
    private static function notice(array $l,array $record,?array $watch,\DateTimeImmutable $now,?array &$claim): ?array
    {
        $d=$l['domain'];$user=$l['user'];$identity='departure:'.$record['identity']['service_id'].':'.$record['identity']['authority_generation'];$renewals=[];
        foreach($record['invoices'] as $o){
            if(!in_array($o['outcome'],['cancelled_unpaid','credited'],true)){continue;}
            $invoice=$l['invoices']->firstWhere('id',$o['invoice_id']);$credited=$o['outcome']==='credited';
            $renewals[]=['invoice'=>(string)($invoice?->number??$o['invoice_id']),'credited'=>$credited,
                'amount'=>RenewalReminders::money($credited?PricingRule::decimal((int)$o['credit_minor']):(string)$invoice?->total)];
        }
        $zone=new \DateTimeZone(config('domains.time_zone')?:config('app.timezone'));
        $data=['domain'=>$d->domain,'deleted'=>$record['reason']==='deleted','same_registrar'=>$record['reason']==='other_account',
            'date'=>$now->setTimezone($zone)->format('M j, Y'),'renewals'=>$renewals,'dns'=>$record['dns_case'],
            'monitored'=>($watch['state']??null)==='active','domain_url'=>url('/domains/'.$d->id),
            'support_url'=>\Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl()??'','company'=>\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company()];
        try{$claim=ReminderDelivery::reserve((int)$d->id,$identity,'domains_domain_departed',$data,$user,$now->getTimestamp());}
        catch(\Illuminate\Database\QueryException|\PDOException $e){throw $e;}
        catch(\Throwable){ObligationAlerts::record($d,'template_failed',$now->getTimestamp(),null,$identity);return null;}
        if(!$claim){return null;}
        return ['delivery_key'=>hash('sha256',$d->id.':'.$identity),'subject'=>$claim['mail']?->envelope()->subject,'to'=>$claim['mail']?(string)$user->email:null];
    }
    private static function alert(int $id,string $code,int $now): void
    {
        try{DB::transaction(function()use($id,$code,$now):void{$d=Domain::whereKey($id)->lockForUpdate()->first();if($d){ObligationAlerts::record($d,$code,$now);}});}
        catch(\Throwable $e){try{logger()->warning('Domains departure email failure could not be recorded.',['domain_id'=>$id,'exception_class'=>$e::class]);}catch(\Throwable){}}
    }
    /** First blocking line in check order, plus the renewals confirmation closes and the completed ones it keeps.
     * @param \Closure(int):?array $money a locked load or a projection; null means unreadable
     * @return array{block:?array,close:list<Operation>,credit:list<Operation>,kept:list<Operation>} */
    private static function assess(array $l,\Closure $money,int $now,bool $ownerHeld): array
    {
        $d=$l['domain'];$blocks=[];$close=[];$credit=[];$kept=[];
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
                if(self::returnSending($l,$r,(int)$invoice)){$block(self::RETURN_SENDING,$invoice);}
                if($r['pending']){$block(self::PAYMENT_PROCESSING,$invoice);}
            }
            $generation=$op->authority_generation===$d->authority_generation&&(int)$op->service_id===(int)$d->service_id;
            if($op->state==='succeeded'){$kept[]=$op;continue;}
            if(!in_array($op->state,[...self::OPEN,...self::TERMINAL],true)){$block(self::AT_REGISTRAR,$invoice,(int)$op->id);continue;}
            if(in_array($op->state,self::OPEN,true)&&$generation&&RenewalCycles::canRetireUnpaid($op,$l)){$close[]=$op;continue;}
            if(in_array($op->state,self::OPEN,true)&&$generation&&$r!==null&&self::paidUnsent($op,$l)){$credit[]=$op;continue;}
            // A retry predecessor shares its paid invoice with the child that completed it: history of the kept child.
            if($op->state==='rejected'&&self::retriedToSuccess($op,$l)){continue;}
            // Closed earlier with nothing left on its invoice: history, not part of this closure.
            if(in_array($op->state,self::TERMINAL,true)&&$r!==null&&InvoiceMoney::settled($r)){continue;}
            // Closed unsent earlier with money still free on its invoice: the same credit closure, without closing it again.
            if($op->state==='rejected'&&$generation&&$r!==null&&self::closedUnsent($op,$l)){$credit[]=$op;continue;}
            if(in_array($op->state,self::TERMINAL,true)&&$invoice===null){continue;}
            $block(self::RENEWAL_REVIEW,$invoice,(int)$op->id);
        }
        // Acquisitions keep their completed history and an adoption's proved history; one that never finished is not a departure.
        foreach($l['operations']->whereIn('action',['register','transfer']) as $op){
            if($op->state!=='succeeded'&&!HoldingAnchor::adopted($op,$l)){$block(self::RENEWAL_REVIEW,$op->invoice_id===null?null:(int)$op->invoice_id,(int)$op->id);}
        }
        $at=self::absenceAt($d);
        if($at===null||$now-$at>self::FRESH){$block(self::STALE);}
        usort($blocks,fn($a,$b)=>$a['line']<=>$b['line']);
        return ['block'=>$blocks[0]??null,'close'=>$close,'credit'=>$credit,'kept'=>$kept];
    }
    /** Reserved money waits unless every reserved share belongs to a proved closed renewal and is still local. */
    private static function returnSending(array $l,array $money,int $invoice): bool
    {
        $reserved=array_sum(array_column($money['receipts'],'reserved'));
        if($reserved===0){return false;}
        $local=0;$d=$l['domain'];
        foreach(self::renewals($l)->where('invoice_id',$invoice) as $op){
            if($op->state!=='rejected'||$op->authority_generation!==$d->authority_generation
                ||(int)$op->service_id!==(int)$d->service_id||!self::closedUnsent($op,$l)){continue;}
            foreach(ReceiptReturns::localAllocations($op,$money) as $a){$local+=$a['amount_minor'];}
        }
        return $reserved!==$local;
    }
    /** Paid or part paid, never attempted at the registrar and still on the current expiry: the same proof the renewal
     * funds closure checks before it closes the request unsent. Reads only. */
    private static function paidUnsent(Operation $op,array $l): bool
    {
        $d=$l['domain'];$invoice=$l['invoices']->firstWhere('id',$op->invoice_id);
        if($op->action!=='renew'||$op->funding_basis!=='paid_invoice'||!$invoice||!in_array($invoice->status,['paid','pending'],true)
            ||!RenewalCycles::registrarVirgin($op)||!RenewalCycles::moneyMoved($l,(int)$invoice->id)
            ||$op->base_expires_at?->format('Y-m-d')!==$d->provider_expires_at?->format('Y-m-d')){return false;}
        try{RefundProof::requireUnsent($op,$d);RefundPayment::requireLine($op,$l);return true;}catch(\DomainException){return false;}
    }
    /** A renewal the funds closure already closed unsent with its retained receipts, as the departure credit requires. */
    private static function closedUnsent(Operation $op,array $l): bool
    {
        if(!isset($op->attempt['terminal']['transactions'])||!FundsWaits::closedRenewal($op,$l['domain'])){return false;}
        try{RefundProof::requireUnsent($op,$l['domain']);RefundPayment::requireLine($op,$l);return true;}catch(\DomainException){return false;}
    }
    /** Proved unsent retry history whose chain on the same paid debt ends in a completed renewal. */
    private static function retriedToSuccess(Operation $op,array $l): bool
    {
        for($i=0;$i<=3&&$op->state==='rejected';$i++){
            if(!RenewalRetries::resolved($op,$l['domain'])){return false;}
            $op=$l['operations']->firstWhere('operation_key',$op->policy['unsent_retry']['next_key']);
            if(!$op){return false;}
        }
        return $op->state==='succeeded';
    }
    private static function creditMinor(array $l): int
    { return PricingRule::minor((string)(\App\Models\Credit::where('user_id',$l['user']->id)->where('currency_code','USD')->value('amount')??'0.00')); }
    /** @return list<int> renewal invoice ids, ascending */
    private static function invoiceIds(array $l): array
    { return self::renewals($l)->pluck('invoice_id')->filter()->map(fn($id)=>(int)$id)->unique()->sort()->values()->all(); }
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
