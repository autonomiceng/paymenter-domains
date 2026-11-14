<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** A paid order held at its contact step with nothing sent goes back to the queue by itself, through the same checks as
 * staff Release. Orders off resumes whenever Orders are on; other causes get a few spaced tries. Then the hold stays for
 * staff, with Cancel and refund. Nothing here cancels or refunds. */
final class ContactStepRetries
{
    /** Seconds to wait before each automatic try, counted from the order's first attempt, then from the last try. */
    public const WAITS=[900,14400,43200];
    public static function candidate(Operation $op): bool
    {
        return $op->state==='held'&&in_array($op->safe_code,ContactProfileHold::HOLDS,true)&&ContactProfileHold::unsent($op,retry:true);
    }
    /** Automatic tries spent on causes other than Orders off. */
    public static function tries(Operation $op): int
    {
        $history=$op->policy['staff_actions']??[];
        if(!is_array($history)){return PHP_INT_MAX;}
        return count(array_filter($history,static fn($e)=>is_array($e)&&($e['automatic']??false)===true&&($e['before']['reason']??null)!=='processing'));
    }
    private static function lastTry(Operation $op): ?\DateTimeImmutable
    {
        $last=null;
        foreach($op->policy['staff_actions']??[] as $e){
            if(is_array($e)&&($e['automatic']??false)===true&&($e['before']['reason']??null)!=='processing'&&is_string($e['at']??null)){
                try{$last=new \DateTimeImmutable($e['at']);}catch(\Throwable){}
            }
        }
        return $last;
    }
    public static function exhausted(Operation $op): bool { return self::tries($op)>=count(self::WAITS); }
    public static function readmit(int $id,Clock $clock): bool
    {
        OperationClaims::outside();$seed=Operation::find($id);
        if(!$seed||!self::candidate($seed)){return false;}
        return DB::transaction(static function()use($seed,$clock):bool {
            $l=HoldingLocks::take($seed->domain);$op=$l['operations']->firstWhere('id',$seed->id);$now=$clock->now();
            if(!$op||!self::candidate($op)||!$l['account']?->processing_enabled){return false;}
            $history=$op->policy['staff_actions']??[];
            if(!is_array($history)||!array_is_list($history)||count($history)>=99){return false;}
            if($op->safe_code!=='processing'){
                $tries=self::tries($op);
                $since=self::lastTry($op)??$op->started_at;
                if($tries>=count(self::WAITS)||$since===null||$since->getTimestamp()+self::WAITS[$tries]>$now->getTimestamp()){return false;}
            }
            try{$op->setRelation('domain',$l['domain']);StaffOperationWrites::requireRelease($op,$l,$now);}catch(\Throwable){return false;}
            $history[]=['action'=>'release','phase'=>'completed','operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,
                'actor_id'=>null,'actor_name'=>'System','automatic'=>true,'reason'=>'Nothing was sent, so it retried by itself.',
                'at'=>$now->format(DATE_ATOM),'before'=>['state'=>'held','reason'=>$op->safe_code],'after'=>['state'=>'queued','reason'=>null]];
            $op->update(['state'=>'queued','safe_code'=>null,'lease_expires_at'=>null,'policy'=>[...($op->policy??[]),'staff_actions'=>$history]]);
            return true;
        });
    }
}
