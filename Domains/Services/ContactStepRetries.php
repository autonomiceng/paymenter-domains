<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Staff\Release\HeldRelease;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAdmission, AcquisitionLane, AcquisitionResult, AcquisitionRetryProof};
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
    public static function exhausted(Operation $op): bool { return self::tries($op)>=count(self::WAITS); }
    /** The current contact hold schedules its next check once. Orders gates that check without changing it. */
    public static function schedule(Operation $op,\DateTimeImmutable $now): bool
    {
        if (!self::candidate($op) || $op->safe_code === 'processing') { return false; }
        $wait = self::WAITS[self::tries($op)] ?? null;
        AcquisitionResult::schedule($op, $wait === null ? null : AcquisitionLane::ContactWait,
            $wait === null ? null : $now->getTimestamp() + $wait);
        return true;
    }
    /** Orders off is the only thing this order waits for: nothing was sent, the contact step can run again, and Orders are
     * off for its account. Pages say Orders paused for it, and the tick holds it as Orders off. $ordersOn: a page's own read. */
    public static function waitsForOrders(Operation $op,?bool $ordersOn=null): bool
    {
        return self::candidate($op)&&ContactProfileHold::releaseBlocked($op)===null
            &&!($ordersOn??(bool)\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->value('processing_enabled'));
    }
    public static function readmit(int $id,Clock $clock): bool
    {
        OperationClaims::outside();$seed=Operation::find($id);
        if(!$seed||!self::candidate($seed)){return false;}
        if (!\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$seed->server_id)->value('processing_enabled')) { return false; }
        if (AcquisitionRetryProof::ordersOffHold($seed)) { return app(OperationClaims::class)->readmitOrdersOff($id); }
        $retry = AcquisitionResult::continuation($seed);
        if ($retry?->lane !== AcquisitionLane::ContactWait || !$retry->due($clock->now())) { return false; }
        return FreshTransaction::run(static function()use($seed,$clock):bool {
            $l=HoldingLocks::take($seed->domain);$op=$l['operations']->firstWhere('id',$seed->id);$now=$clock->now();
            if(!$op||!self::candidate($op)||!$l['account']?->processing_enabled){return false;}
            $retry = AcquisitionResult::continuation($op, true);
            $policy=self::released($op,(string)$op->safe_code,$now);
            if ($policy === null || $retry?->lane !== AcquisitionLane::ContactWait || !$retry->due($now)) { return false; }
            try{$op->setRelation('domain',$l['domain']);HeldRelease::require($op,$l,$now);}catch(\Throwable){return false;}
            AcquisitionResult::admit($op, AcquisitionAdmission::ContactRetry, $retry->revision,
                $op->acquisitionAttemptFacts()?->token, $now,
                static fn () => $op->update(['state'=>'queued','safe_code'=>null,'lease_expires_at'=>null,'policy'=>$policy]));
            return true;
        });
    }
    /** The policy with the automatic retry recorded for staff. Null when History is full. */
    public static function released(Operation $op,string $from,\DateTimeImmutable $now): ?array
    {
        $history=$op->policy['staff_actions']??[];
        if(!is_array($history)||!array_is_list($history)||count($history)>=99){return null;}
        $history[]=['action'=>'release','phase'=>'completed','operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,
            'actor_id'=>null,'actor_name'=>'System','automatic'=>true,'reason'=>'Nothing was sent, so it retried by itself.',
            'at'=>$now->format(DATE_ATOM),'before'=>['state'=>'held','reason'=>$from],'after'=>['state'=>'queued','reason'=>null]];
        return [...($op->policy??[]),'staff_actions'=>$history];
    }
}
