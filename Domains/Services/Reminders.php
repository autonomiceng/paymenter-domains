<?php
// Adapted from Enom 444f31c Services/{Reminders,TransferNotifications}.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Dormant explicit worker. No provider calls, financial mutation, registration or default activation. */
final readonly class Reminders
{
    public function __construct(private Clock $clock,private AccountBindings $bindings) {}
    public function sweep(int $accounts=8,int $limit=25):int
    {
        OperationClaims::outside();$sent=0;
        if($accounts<1||$accounts>32){throw new \InvalidArgumentException('Invalid account batch.');}
        for($turn=0;$turn<$accounts;$turn++){
            // Claim one account only; an interrupted account cannot advance every other cursor.
            foreach(ReminderSchedules::next($this->clock->now()->getTimestamp(),$limit) as $id){
                try{$sent+=$this->remind((int)$id)?1:0;}catch(\Throwable){$this->failure((int)$id,'reminder_failed');}
            }
        }
        return $sent;
    }
    public function remind(int $id):bool
    {
        OperationClaims::outside();$now=$this->clock->now();$seed=Domain::findOrFail($id);
        if($seed->adoptionPending()){return false;}
        $claim=DB::transaction(function()use($seed,$now):?array {
            $l=HoldingLocks::take($seed);$d=$l['domain'];
            $schedule=DB::table('domains_reminder_schedules')->where('server_id',$d->server_id)->lockForUpdate()->first();
            $deliver=$schedule&&$schedule->account_fingerprint===$d->account_fingerprint&&$l['server']->enabled;
            $schedule??=(object)['enabled'=>false,'before_days'=>'[]','after_days'=>'[]'];
            $alerts=ObligationAlerts::retained($l,$now->getTimestamp());
            if(isset($schedule->account_fingerprint)&&$schedule->account_fingerprint!==$d->account_fingerprint){$alerts[]=ObligationAlerts::record($d,'schedule_binding',$now->getTimestamp());}
            $candidates=[RenewalReminders::plan($l,$schedule,$now,$alerts,$this->bindings),TransferNotifications::plan($l,$now,$alerts),self::refund($l,$now,$alerts)];$notice=null;
            foreach($candidates as $candidate){if($candidate&&($schedule->enabled||in_array($candidate['template'],['domains_transfer_code_paid','domains_domain_order_refunded'],true))&&!DB::table('domains_reminder_deliveries')->where('delivery_key',hash('sha256',$d->id.':'.$candidate['identity']))->exists()){$notice=$candidate;break;}}
            foreach(DB::table('domains_reminder_deliveries')->where('domain_id',$id=$d->id)->whereIn('state',['handoff','uncertain'])->get() as $delivery){
                $alerts[]=ObligationAlerts::record($d,'delivery_uncertain',$now->getTimestamp());
            }
            ObligationAlerts::finish($d,$alerts,$now->getTimestamp());
            if(!$notice||!$deliver){return null;}
            try{$claim=ReminderDelivery::reserve((int)$d->id,$notice['identity'],$notice['template'],$notice['data'],$l['user'],$now->getTimestamp());
                return $claim?[...$claim,'failure_key'=>ObligationAlerts::key($d,'template_failed',null,$notice['identity'])]:null;}
            catch(\Throwable){ObligationAlerts::record($d,'template_failed',$now->getTimestamp(),null,$notice['identity']);return null;}
        });
        $sent=ReminderDelivery::handoff($claim,$now->getTimestamp());
        if($sent){DB::table('domains_obligation_alerts')->where('alert_key',$claim['failure_key'])->whereNull('resolved_at')->update(['resolved_at'=>$now->getTimestamp()]);}
        if($claim&&!$sent){$this->failure($id,'delivery_uncertain');}return $sent;
    }
    private function failure(int $id,string $code):void
    {
        try{DB::transaction(function()use($id,$code):void {
            $d=Domain::whereKey($id)->lockForUpdate()->first();if($d){ObligationAlerts::record($d,$code,$this->clock->now()->getTimestamp());}
        });}catch(\Throwable){} // Database failure is not a customer-send permission.
    }
    private static function refund(array $l,\DateTimeImmutable $now,array &$alerts):?array
    {
        $d=$l['domain'];
        foreach($l['operations'] as $op){
            $r=$op->funding['refund']??null;
            if($op->state!=='rejected'||!is_array($r)||($r['to']??null)!=='account_credit'||($r['currency']??null)!=='USD'
                ||$op->account_fingerprint!==$d->account_fingerprint||$op->authority_generation!==$d->authority_generation){continue;}
            $credit=$l['credit'];
            if(!$credit||$credit->id!==($r['credit_id']??null)||$credit->user_id!==$d->user_id||$credit->currency_code!=='USD'){continue;}
            $identity='refund:'.$op->id;
            if(DB::table('domains_reminder_deliveries')->where('delivery_key',hash('sha256',$d->id.':'.$identity))->exists()){continue;}
            try{$at=new \DateTimeImmutable($r['at']??'invalid');}catch(\Throwable){$at=null;}
            if(!$at||$at>$now||$at<$now->modify('-2 days')){$alerts[]=ObligationAlerts::record($d,'credit_notice_overdue',$now->getTimestamp(),(int)$op->id);continue;}
            return ['identity'=>$identity,'template'=>'domains_domain_order_refunded','data'=>['domain'=>$d->domain,
                'action'=>match($op->action){'register'=>'registration','transfer'=>'transfer','renew'=>'renewal','privacy_purchase'=>'privacy purchase','privacy_renew'=>'privacy renewal',default=>'domain request'},
                'amount'=>RenewalReminders::money($r['amount']),'domain_url'=>url('/domains/'.$d->id)]];
        }
        return null;
    }
}
