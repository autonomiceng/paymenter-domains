<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Safe durable staff inbox; expiry/account/generation/obligation distinguish once-only alerts. */
final class ObligationAlerts
{
    public const LABELS=[
        'transfer_watch_relink'=>'External watch needs relinking after transfer · review source history and notices',
        'acquisition_retirement_review'=>'Unpaid order needs review · automatic retirement was refused; the name stays reserved',
        'acquisition_late_money'=>'Paid after order closed · check the invoice and its payments; nothing is sent to the registrar',
        'native_identity_missing'=>'Customer, order or service missing. The domain and its name stay reserved until a later release adds a resolution step. Do not restore deleted records manually.',
        'unknown_expiry'=>'Expiry unknown · confirm the saved expiry before contacting the customer',
        'unknown_price'=>'Renewal price unknown · review pricing before contacting the customer',
        'unknown_cost'=>'Renewal cost unknown · review the holding account price',
        'schedule_binding'=>'Reminder account changed · review the saved schedule before enabling customer notices',
        'credit_notice_overdue'=>'Account credit notice overdue · review the recorded credit before contacting the customer',
        'renewal_unpaid'=>'Unpaid renewal invoice cancelled · review renewal options with the customer',
        'missing_execution'=>'Complimentary renewal request missing · review renewal authority before expiry',
        'missing_obligation'=>'Renewal invoice missing · review billing before expiry',
        'held_obligation'=>'Renewal on hold · review the request before expiry',
        'invalid_invoice'=>'Renewal invoice needs review · check its owner, line and due date',
        'below_cost'=>'Renewal below cost · billing continues; review the margin',
        'observation_review'=>'Saved domain refresh needs review · inspect the read work before resuming',
        'operation_review'=>'Request needs staff review · inspect the retained operation evidence',
        'unknown_payment_time'=>'Transfer payment time unknown · verify payment before sending a deadline',
        'delivery_uncertain'=>'Notification handoff unconfirmed · check the mail queue before any resend',
        'template_failed'=>'Notification unavailable · check its template and delivery channels',
        'reminder_failed'=>'Reminder check failed · review the holding account and saved evidence',
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
        if($old){$q->update(['last_seen'=>$now,'resolved_at'=>null]);}
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
            if(in_array($op->state,['held','uncertain'],true)||($p['reconciliation']['staff_alert']??false)||($p['renewal_execution']['staff_alert']??false)){
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
    /** Resolve only after a successful, complete inspection, retaining all historical rows. */
    public static function finish(Domain $d,array $active,int $now):void
    {
        $q=DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->whereNull('resolved_at')->whereNotIn('code',['template_failed','reminder_failed','acquisition_retirement_review','acquisition_late_money','transfer_watch_relink']);
        if($active){$q->whereNotIn('alert_key',$active);}$q->update(['resolved_at'=>$now]);
    }
}
