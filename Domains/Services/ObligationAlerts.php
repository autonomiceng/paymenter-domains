<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
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
        'credit_notice_overdue'=>'Account credit notice overdue. Check the credit before you contact the customer.',
        'renewal_unpaid'=>'Unpaid renewal invoice cancelled. Talk to the customer about renewal.',
        'missing_execution'=>'Free renewal request missing. Check before expiry.',
        'missing_obligation'=>'Renewal invoice missing. Check billing before expiry.',
        'held_obligation'=>'Renewal on hold. Check the request before expiry.',
        'invalid_invoice'=>'Renewal invoice needs a check: owner, line and due date.',
        'below_cost'=>'Below cost. Billing continues. Check the margin.',
        'observation_review'=>'Sync needs a check before you resume it.',
        'operation_review'=>'Registrar request needs a check. Open the request.',
        'unknown_payment_time'=>'Transfer payment time missing. Check the payment before you send a deadline.',
        'delivery_uncertain'=>'Reminder not confirmed. Check its delivery before you resend.',
        'template_failed'=>'Reminder failed. Check its template and channels.',
        'reminder_failed'=>'Renewal reminder check failed. Check the registrar account.',
    ];
    public static function key(Domain $d,string $code,?int $operationId=null,?string $identity=null):string
    {
        return hash('sha256',implode(':',[$d->id,$d->account_fingerprint,$d->authority_generation,$d->provider_expires_at?->format('Y-m-d')??'unknown',$operationId??0,$code,$identity??'']));
    }
    public static function record(Domain $d,string $code,int $now,?int $operationId=null,?string $identity=null):string
    {
        if(!isset(self::LABELS[$code])){throw new \InvalidArgumentException('Unknown obligation alert.');}
        $key=self::key($d,$code,$operationId,$identity);
        $q=DB::table('domains_obligation_alerts')->where('alert_key',$key);$old=(clone $q)->first();
        if($old){$q->update(['last_seen'=>$now,...($code==='acquisition_late_money'?[]:['resolved_at'=>null])]);}
        else{DB::table('domains_obligation_alerts')->insert(['alert_key'=>$key,'domain_id'=>$d->id,'generation'=>$d->authority_generation,'code'=>$code,
            'operation_id'=>$operationId,'first_seen'=>$now,'last_seen'=>$now]);}
        return $key;
    }
    public static function retained(array $l,int $now):array
    {
        $d=$l['domain'];$keys=[];
        foreach($l['operations'] as $op){
            if($op->account_fingerprint!==$d->account_fingerprint||$op->authority_generation!==$d->authority_generation){continue;}
            $p=$op->policy??[];
            if($op->action==='renew'&&isset($p['price'])){
                $code=$p['price']['staff_alert']??null;
                if($code!==null||($p['price']['retained']??false)){
                    $keys[]=self::record($d,$code==='below_cost'?'below_cost':'unknown_cost',$now,(int)$op->id);
                }
            }
            if(in_array($op->state,['held','uncertain'],true)||($p['reconciliation']['staff_alert']??false)&&!self::corrected($op,$l['operations'],$d)||($p['renewal_execution']['staff_alert']??false)){
                $keys[]=self::record($d,$op->action==='renew'&&$op->state==='held'?'held_obligation':'operation_review',$now,(int)$op->id);
            }
        }
        if(Schema::hasTable('domains_observation_work')){
            $follow=$d->observation['followup']??[];
            $phase=isset($follow['operation_key'])&&($follow['generation']??null)===$d->authority_generation?hash('sha256',$follow['operation_key']):'holding';
            if(DB::table('domains_observation_work')->where('domain_id',$d->id)->where('generation',$d->authority_generation)
                ->where('account_fingerprint',$d->account_fingerprint)->where('phase',$phase)
                ->whereIn('safe_code',['budget_exhausted','read_unavailable',...ReadFailure::CODES])->exists()){$keys[]=self::record($d,'observation_review',$now);}
        }
        return $keys;
    }
    /** An alert stays live unless its own cause is proven cleared. Only two causes can be: a registration correction with a
     * confirmed fix, and a hold staff released that nothing has raised since. The next inspection closes those; staff pages
     * stop counting them now. Anything we can't read stays live. */
    public static function outdated(object $alert,?Operation $op,Collection $operations,Domain $d):bool
    {
        if(!$op){return false;}
        try{return $alert->code==='operation_review'&&self::corrected($op,$operations,$d)
            ||in_array($alert->code,['operation_review','held_obligation'],true)&&self::released($op,(int)$alert->last_seen);}
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
        return in_array($op->state,['queued','running','pending'],true)&&($last['action']??null)==='release'&&($last['phase']??null)==='completed'
            &&is_string($last['at']??null)&&$lastSeen<strtotime($last['at']);
    }
    /** Resolve only after a successful, complete inspection, retaining all historical rows. */
    public static function finish(Domain $d,array $active,int $now):void
    {
        $q=DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->whereNull('resolved_at')->whereNotIn('code',['template_failed','reminder_failed','acquisition_retirement_review','acquisition_late_money','transfer_watch_relink']);
        if($active){$q->whereNotIn('alert_key',$active);}$q->update(['resolved_at'=>$now]);
    }
}
