<?php
// Adapted from Enom 444f31c Services/TransferNotifications.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Missing-code notices use retained paid time and current native funding, never callback time. */
final class TransferNotifications
{
    public static function plan(array $l,\DateTimeImmutable $now,array &$alerts):?array
    {
        $d=$l['domain'];if($d->status!=='transfer_pending'||!in_array($l['service']->status,['active','pending'],true)
            ||$l['cancel']->contains('service_id',$d->service_id)){return null;}
        foreach($l['operations'] as $op){
            if($op->action!=='transfer'||!in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true)
                ||$op->account_fingerprint!==$d->account_fingerprint||$op->authority_generation!==$d->authority_generation
                ||array_key_exists('epp',$op->payload??[])&&$op->payload['epp']!==null){continue;}
            try{RefundProof::requireUnsent($op,$d);RefundPayment::requireCurrent($op,$l);}catch(\Throwable){continue;}
            $deadline=TransferInputs::deadline($op,$now);
            if(!$deadline){$alerts[]=ObligationAlerts::record($d,'unknown_payment_time',$now->getTimestamp(),(int)$op->id);continue;}
            if($now>=$deadline){continue;}
            // If first discovered in the final week, only the more urgent notice is sent.
            $kind=$now>=$deadline->modify('-7 days')?'reminder':'paid';$identity='transfer:'.$op->id.':'.$kind;
            return ['identity'=>$identity,'template'=>'domains_transfer_code_'.$kind,'data'=>['domain'=>$d->domain,
                'deadline'=>$deadline->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y, g:i A').' UTC',
                'amount'=>RenewalReminders::money($op->amount),'domain_url'=>url('/domains/'.$d->id.'/transfer-code')]];
        }
        return null;
    }
}
