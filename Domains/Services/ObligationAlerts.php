<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
/** Safe durable staff inbox; expiry/account/generation/obligation distinguish once-only alerts. */
final class ObligationAlerts
{
    public const LABELS=[
        'transfer_watch_relink'=>'Domain monitoring needs relinking after the transfer.',
        'acquisition_retirement_review'=>'Unpaid order needs review. The name stays reserved.',
        'acquisition_late_money'=>'Payment activity after the order closed. Check the invoice.',
        'native_identity_missing'=>"Customer, order or service missing. The name stays reserved. Don't restore records by hand.",
        'unknown_expiry'=>'Expiry missing. Check it before you contact the customer.',
        'unknown_price'=>'Renewal price missing. Check pricing before you contact the customer.',
        'unknown_cost'=>"Cost missing. Check the registrar account's price.",
        'schedule_binding'=>'Reminder account changed. Check the schedule before you turn on notices.',
        'account_money_hold'=>'Account on hold: a payment was lost to a bank dispute. Check the dispute and the customer, then release the hold.',
        'dispute_recovery'=>'Bank dispute recovery needs a check. Automatic checks continue.',
        'refund_repair'=>'Money return needs a check. Review the request, invoice and gateway outcome. Do not return it again. Automatic checks continue.',
        'funds_wait_review'=>'Registrar funds wait ended. Check the request outcome before returning money.',
        'renewal_funds'=>'Renewal needs registrar funds now. Fund the account before the safe expiry deadline; the system retries.',
        'credit_notice_overdue'=>'Account credit notice overdue. Check the credit before you contact the customer.',
        'renewal_unpaid'=>'Unpaid renewal invoice cancelled. Talk to the customer about renewal.',
        'missing_execution'=>'Free renewal request missing. Check before expiry.',
        'missing_obligation'=>'Renewal invoice missing. Check billing before expiry.',
        'imported_renewal_unproven'=>AdoptionRenewalEligibility::STAFF,
        'held_obligation'=>'Renewal on hold. Check the request before expiry.',
        'invalid_invoice'=>'Renewal invoice needs a check: owner, line and due date.',
        'below_cost'=>'Below cost. Billing continues. Check the margin.',
        'observation_review'=>'Sync stopped. Use Resume sync.',
        'departure_review'=>'Not in our registrar account. Renewal billing paused. Check the registrar.',
        'operation_review'=>'Registrar request needs a check. Check the request.',
        'unknown_payment_time'=>'Transfer payment time missing. Check the payment before you send a deadline.',
        'delivery_uncertain'=>'Reminder not confirmed. Check its delivery before you resend.',
        'template_failed'=>'Reminder failed. Check its template and channels.',
        'reminder_failed'=>'Renewal reminder check failed. Check the registrar account.',
    ];
    public const ACTIVE='active';
    public const CLEAR='clear';
    /** The evidence can't be read, so an open alert stays as it is. */
    public const UNKNOWN='unknown';
    private const REVIEW=['operation_review','held_obligation'];
    /** Request price alerts. RenewalReminders also raises unknown_cost for the current cost, without a request. */
    private const PRICE=['below_cost','unknown_cost'];
    private const IN_FLIGHT=['queued','running','pending'];
    /** Transfers finish within 7 days, and a paid renewal this close to expiry can't wait. */
    private const WAIT_DAYS=7;
    /** Transfer polling backs off to twelve hours, so a full day with no read means reads have stopped. */
    private const READ_STALE_SECONDS=86400;
    /** Shared read scope for the menu, filter and detail list. Only in-flight reviews need encrypted release data.
     * One candidate query, then one caller query; no domain projection or per-request database reads. */
    public static function open(?array $domains=null): \Illuminate\Database\Query\Builder
    {
        $open=DB::table('domains_obligation_alerts')->whereNull('domains_obligation_alerts.resolved_at');
        if ($domains!==null) { $open->whereIn('domains_obligation_alerts.domain_id',$domains); }
        $candidates=(clone $open)->join('domains_operations as review_operation','review_operation.id','=','domains_obligation_alerts.operation_id')
            ->whereColumn('review_operation.domain_id','domains_obligation_alerts.domain_id')
            ->whereIn('domains_obligation_alerts.code',self::REVIEW)->whereIn('review_operation.state',self::IN_FLIGHT)
            ->get(['domains_obligation_alerts.id','domains_obligation_alerts.last_seen','review_operation.state','review_operation.policy']);
        $released=[];
        foreach ($candidates as $row) {
            // Use the model's encrypted-array cast on these two columns only; no model query or relations.
            $op=(new Operation)->setRawAttributes(['state'=>$row->state,'policy'=>$row->policy],true);
            try { if (self::released($op,(int)$row->last_seen)) { $released[]=(int)$row->id; } }
            catch (\Throwable) { /* Unreadable history cannot prove the alert cleared. */ }
        }
        return $open->whereIntegerNotInRaw('domains_obligation_alerts.id',$released);
    }
    public static function key(Domain $d,string $code,?int $operationId=null,?string $identity=null):string
    {
        return hash('sha256',implode(':',[$d->id,$d->account_fingerprint,$d->authority_generation,$d->provider_expires_at?->format('Y-m-d')??'unknown',$operationId??0,$code,$identity??'']));
    }
    public static function record(Domain $d,string $code,int $now,?int $operationId=null,?string $identity=null):string
    {
        if(!isset(self::LABELS[$code])){throw new \InvalidArgumentException('Unknown obligation alert.');}
        $key=self::key($d,$code,$operationId,$identity);
        $q=DB::table('domains_obligation_alerts')->where('alert_key',$key);$old=(clone $q)->first();
        if($old){
            $ack=$code==='acquisition_late_money'&&collect($d->renewal_policy['acquisition_payment_reviews']??[])->contains(fn($review)=>in_array((int)$old->id,$review['alert_ids']??[],true));
            $q->update(['last_seen'=>$now,...($ack?[]:['resolved_at'=>null])]);
        }
        else{DB::table('domains_obligation_alerts')->insert(['alert_key'=>$key,'domain_id'=>$d->id,'generation'=>$d->authority_generation,'code'=>$code,
            'operation_id'=>$operationId,'first_seen'=>$now,'last_seen'=>$now]);}
        return $key;
    }
    public static function retained(array $l,int $now):array
    {
        $d=$l['domain'];$keys=[];
        if (($key=RenewalOwnership::review($d,$now))!==null) { $keys[]=$key; }
        // Active records the alert; unknown keeps its open rows as they are, including rows keyed at an earlier expiry; clear lets finish() resolve them.
        $keep=static function(string $state,string $code,?int $operationId=null)use($d,$now,&$keys):void {
            if($state===self::ACTIVE){$keys[]=self::record($d,$code,$now,$operationId);}
            elseif($state===self::UNKNOWN){
                $open=DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',$code)->whereNull('resolved_at');
                array_push($keys,...($operationId===null?$open->whereNull('operation_id'):$open->where('operation_id',$operationId))->pluck('alert_key')->all());
            }
        };
        foreach($l['operations'] as $op){
            if($op->account_fingerprint!==$d->account_fingerprint||$op->authority_generation!==$d->authority_generation){continue;}
            foreach(self::PRICE as $code){$keep(self::price($op,$d,$code),$code,(int)$op->id);}
            $keep(self::review($op,$l['operations'],$d,$now),$op->action==='renew'&&$op->state==='held'?'held_obligation':'operation_review',(int)$op->id);
        }
        if(Schema::hasTable('domains_observation_work')&&!RenewalOwnership::blocked($d)){$keep(self::observation($d),'observation_review');}
        return $keys;
    }
    /** The request's saved price evaluation, for one price alert code. The sweep, the domain page and the request Cost row read this one check. */
    public static function price(Operation $op,Domain $d,string $code):string
    {
        try{
            $p=$op->policy??[];
            if($op->action!=='renew'||!isset($p['price'])||$op->account_fingerprint!==$d->account_fingerprint||$op->authority_generation!==$d->authority_generation){return self::CLEAR;}
            // Retained retail is informational; RenewalReminders checks current cost independently.
            $saved=$p['price']['staff_alert']??null;
            // Completed losses still matter; other finished requests incurred no renewal margin.
            $closed=$op->state!=='succeeded'&&in_array($op->state,RequestStatus::FINISHED,true);
            $raised=$saved==='below_cost'?$code==='below_cost'&&!$closed
                :$code==='unknown_cost'&&$saved!=='retained_renewal'&&($saved!==null||($p['price']['retained']??false));
            return $raised?self::ACTIVE:self::CLEAR;
        }catch(\Throwable){return self::UNKNOWN;}
    }
    /** Whether a request needs a staff check: held or unconfirmed, or a saved result that still needs a person, unless it is a wait the system clears by itself. */
    public static function review(Operation $op,Collection $operations,Domain $d,int $now):string
    {
        try{return !self::waiting($op,$d,$now)&&(in_array($op->state,['held','uncertain'],true)||self::flagged($op,$operations,$d))?self::ACTIVE:self::CLEAR;}
        catch(\Throwable){return self::UNKNOWN;}
    }
    /** Sync stopped for this holding's current identity, by the detail rule (ObservationRecords::stopped). */
    public static function observation(Domain $d):string
    {
        try{return ObservationRecords::stopped($d)?self::ACTIVE:self::CLEAR;}catch(\Throwable){return self::UNKNOWN;}
    }
    /** Waits the system clears by itself, so no one has a step to take yet. The request keeps every fact, and when the wait
     * runs out the next inspection raises the same alert. Anything unproven alerts: an uncertain transfer may not have been accepted.
     * A transfer wait is quiet only while its reads work and will continue: a failed read, a stale one or stopped checks alert.
     * An unsent change the runtime tries again is quiet until its tries run out. */
    private static function waiting(Operation $op,Domain $d,int $now):bool
    {
        if(ManagementEvidence::recoverable($op)){return true;}
        $window=self::WAIT_DAYS*86400;
        if($op->action==='renew'){
            return $op->state==='queued'&&in_array($op->safe_code,[RenewalCode::Rate->value,RenewalCode::Repeat->value,RenewalCode::Reads->value],true)
                &&$d->provider_expires_at?->getTimestamp()>$now+$window;
        }
        $review=$op->policy['reconciliation']??[];$read=$review['read_at']??TransferPolling::start($op);
        $first=TransferPolling::firstPoll($op);
        return $op->state==='uncertain'&&$op->safe_code==='reconciliation_required'
            &&(TransferPolling::expected($op)||$first)&&$now<TransferPolling::deadline($op)
            &&empty($review['read_failed'])&&$read>$now-self::READ_STALE_SECONDS
            &&(($review['transfer_status']??null)==='pending'||$first);
    }
    /** Existing detail-only cleanups that need additional encrypted evidence. Releases are already removed by open().
     * Anything we can't read stays live. */
    public static function outdated(object $alert,?Operation $op,Collection $operations,Domain $d):bool
    {
        if($alert->code===RenewalOwnership::REVIEW){try{return !HoldingReadRetry::absent($d);}catch(\Throwable){return false;}}
        if($alert->code==='observation_review'){return self::observation($d)===self::CLEAR;}
        if(!$op){return false;}
        if(in_array($alert->code,self::PRICE,true)){return self::price($op,$d,$alert->code)===self::CLEAR;}
        if(in_array($alert->code,self::REVIEW,true)&&self::settledRenewal($op,$d)&&!self::flagged($op,$operations,$d)){return true;}
        // A wait the system clears by itself is clear in review() too, so the next inspection resolves this row.
        if(in_array($alert->code,self::REVIEW,true)){try{if(self::waiting($op,$d,app(Clock::class)->now()->getTimestamp())){return true;}}catch(\Throwable){}}
        // A refusal before sending (dispatch, balance or claim checks) records a review alert. Once the request is sent and inside the registrar's
        // normal wait it is In progress, so that alert is outdated; an unconfirmed result records a fresh one (retained()).
        if(in_array($alert->code,self::REVIEW,true)&&in_array($op->state,['running','pending'],true)){try{if(!self::flagged($op,$operations,$d)){return true;}}catch(\Throwable){}}
        // An ended domain whose request provably never went out and whose money is settled has nothing left to check: the order is over.
        if(in_array($alert->code,self::REVIEW,true)&&in_array($d->status,['cancelled','registration_rejected','transfer_rejected'],true)){
            try{if((array_key_exists('refund',$op->funding??[])||$op->funding_basis!=='paid_invoice')&&OperationTerminalProof::safe($op,$d)){return true;}}catch(\Throwable){}
        }
        try{return in_array($alert->code,self::REVIEW,true)&&($op->state==='succeeded'&&!self::flagged($op,$operations,$d)||ManagementEvidence::recoverable($op));}
        catch(\Throwable){return false;}
    }
    /** A request that succeeds, or that staff mark as failed, closes its own alert now: a paused sync runs no inspection, and the Open alerts filter reads the row.
     * Runs inside the writer's save, so it reads its own Domain and leaves the writer's loaded relation alone. */
    public static function settle(Operation $op):void
    {
        if(($op->state!=='succeeded'&&!ManagementEvidence::staffFailed($op))||!$op->wasChanged('state')||!Schema::hasTable('domains_obligation_alerts')){return;}
        $open=DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->whereIn('code',self::REVIEW)->whereNull('resolved_at');
        if(!$open->exists()||!($d=Domain::find($op->domain_id))||self::flagged($op,$d->operations()->get(),$d)){return;}
        $open->update(['resolved_at'=>app(Clock::class)->now()->getTimestamp()]);
    }
    /** The saved result still needs a person: a charge difference, a low margin or a registration correction with no confirmed fix. */
    private static function flagged(Operation $op,Collection $operations,Domain $d):bool
    {
        try {
            $p=$op->policy??[];
            return ($p['reconciliation']['staff_alert']??false)&&!self::corrected($op,$operations,$d)||($p['renewal_execution']['staff_alert']??false)&&!self::settledRenewal($op,$d);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) { return true; }
    }
    /** Historical renewal uncertainty clears only with current terminal proof and a settled invoice. */
    public static function settledRenewal(Operation $op,Domain $d): bool
    {
        try{return $op->action==='renew'&&OperationTerminalProof::safe($op,$d)&&$op->invoice_id!==null
            &&InvoiceMoney::settled(InvoiceMoney::project((int)$op->invoice_id));}
        catch(\Throwable){return false;}
    }
    /** Every section the registration flagged was fixed by a successful, confirmed request. The saved flag itself never changes. */
    private static function corrected(Operation $op,Collection $operations,Domain $d):bool
    {
        $review=$op->policy['reconciliation']??[];
        if($op->action!=='register'||$op->state!=='succeeded'||($review['reason']??null)!=='correction_required'){return false;}
        $attempt=$op->attempt??[];$sections=[];
        foreach([$attempt['corrections']??[],$attempt['evidence']['registration']['corrections']??[],$review['observed_corrections']??[]] as $list){
            $list=(array)$list;array_push($sections,...(array_is_list($list)?$list:array_keys($list)));
        }
        foreach(array_unique($sections) as $section){
            if(!$operations->contains(fn(Operation $peer)=>$peer->state==='succeeded'&&($peer->policy['management']['correction_link']['section']??null)===$section
                &&ManagementCorrections::resolvedFor($peer,$op,$d))){return false;}
        }
        return $sections!==[];
    }
    /** Staff's latest action was a release, the request is moving, and no check has raised the alert since. */
    private static function released(Operation $op,int $lastSeen):bool
    {
        $history=$op->policy['staff_actions']??[];$last=$history?end($history):[];
        return in_array($op->state,self::IN_FLIGHT,true)&&($last['action']??null)==='release'&&($last['phase']??null)==='completed'
            &&is_string($last['at']??null)&&$lastSeen<strtotime($last['at']);
    }
    /** Resolve only after a successful, complete inspection, retaining all historical rows. */
    public static function finish(Domain $d,array $active,int $now):void
    {
        $q=DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->whereNull('resolved_at')->whereNotIn('code',['account_money_hold','dispute_recovery','refund_repair','funds_wait_review','renewal_funds','template_failed','acquisition_retirement_review','acquisition_late_money','transfer_watch_relink']);
        if($active){$q->whereNotIn('alert_key',$active);}$q->update(['resolved_at'=>$now]);
    }
}
