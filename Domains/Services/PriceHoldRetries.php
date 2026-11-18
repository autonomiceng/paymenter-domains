<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Retry a paid, unsent acquisition through Release. The normal preflight still enforces both frozen price limits. */
final class PriceHoldRetries
{
    private const MAX_AUTOMATIC_TRIES=3;
    public static function candidate(Operation $op): bool
    {
        return $op->state==='held'&&$op->safe_code==='registrar_price_increased'&&$op->funding_basis==='paid_invoice'
            &&in_array($op->action,['register','transfer'],true)&&OperationClaims::testCallRefusalRetryable($op);
    }
    private static function due(Operation $op,\DateTimeImmutable $now): bool
    {
        $last=$op->started_at;$tries=0;$automaticTries=0;$history=$op->policy['staff_actions']??[];
        // Retained History also needs room for staff Release, its return hold, and Cancel and refund.
        if(!is_array($history)||!array_is_list($history)||count($history)>=95){return false;}
        foreach($history as $event){
            if(($event['action']??null)!=='release'||($event['phase']??null)!=='completed'
                ||($event['before']['reason']??null)!=='registrar_price_increased'){continue;}
            try{$last=new \DateTimeImmutable($event['at']);}catch(\Throwable){return false;}
            $tries++;if(($event['automatic']??false)===true){$automaticTries++;}
        }
        $delay=$tries===0?900:($tries===1?3600:21600);
        return $automaticTries<self::MAX_AUTOMATIC_TRIES&&$last!==null&&$last->getTimestamp()+$delay<=$now->getTimestamp();
    }
    public static function readmit(int $id,Clock $clock): bool
    {
        OperationClaims::outside();$seed=Operation::find($id);
        if(!$seed||!self::candidate($seed)||!self::due($seed,$clock->now())){return false;}
        return FreshTransaction::run(static function()use($seed,$clock):bool {
            $l=HoldingLocks::take($seed->domain);$op=$l['operations']->firstWhere('id',$seed->id);$now=$clock->now();
            if(!$op||!self::candidate($op)||!self::due($op,$now)){return false;}
            $policy=ContactStepRetries::released($op,'registrar_price_increased',$now);
            if($policy===null){return false;}
            try{
                app(AccountBindings::class)->requireProcessingLocked($l['server'],$l['account']);
                StaffOperationWrites::requireRelease($op,$l,$now);
            }catch(\Throwable){return false;}
            StaffOperationWrites::release($op,$l,$now);
            $op->update(['policy'=>$policy]);
            return true;
        });
    }
}
