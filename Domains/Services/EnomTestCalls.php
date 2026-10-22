<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
/** Deployment-wide TEST reservations. Independent commits precede send; never refund a unit. */
final class EnomTestCalls
{
    private static ?object $purchase=null;
    private static int $refusalGeneration=0;
    private static function configuredLimit(): mixed { return config('domains.enom_test_daily_call_cap',50); }
    public static function limit(): int
    { $v=self::configuredLimit();return is_int($v)&&$v>=0?$v:0; }
    public static function day(): string
    { return app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'); }
    /** Lets the registry restore a typed refusal across the verifier's deliberate error redaction. */
    public static function refusalGeneration(): int { return self::$refusalGeneration; }
    public static function refuse(): never {++self::$refusalGeneration;throw new EnomTestCallLimit;}
    public static function refusedSince(int $generation): bool { return self::$refusalGeneration!==$generation; }
    public static function reserve(Environment $environment): string
    {
        if ($environment!==Environment::Test) { return ''; }
        OperationClaims::outside();
        try {
            $day=self::day();$limit=self::limit();
            if ($limit===0) { throw new \DomainException; }
            DB::table(EnomTestCallSchema::TABLE)->insertOrIgnore(['day'=>$day,'used'=>0]);
            // One conditional UPDATE arbitrates concurrent processes, including first-use races.
            if (DB::table(EnomTestCallSchema::TABLE)->where('day',$day)->where('used','<',$limit)->increment('used')!==1) { throw new \DomainException; }
            return $day;
        } catch (\Throwable) { ++self::$refusalGeneration; throw new EnomTestCallLimit; }
    }
    public static function assertReservedDay(string $day): void
    { if ($day!==''&&$day!==self::day()) { ++self::$refusalGeneration;throw new EnomTestCallLimit; } }
    /** Open a single-request Purchase scope; its day-bound unit is reserved after preflight and before seal. */
    public static function withPurchase(AccountBinding $binding, \Closure $work, ?int $operationId=null): mixed
    {
        if ($binding->registrarKey!=='enom' || ($binding->environment!==Environment::Test&&!EnomLive::enabled($binding,'registration')) || ($operationId!==null&&$operationId<1)) { throw new EnomTestCallLimit; }
        if (self::$purchase!==null) { throw new EnomTestCallLimit; }
        self::$purchase=(object)['fingerprint'=>$binding->fingerprint,'operationId'=>$operationId,'day'=>null,'reservedDay'=>null,'consumed'=>false];
        try { return $work(); } finally { self::$purchase=null; }
    }
    public static function assertPurchaseCurrent(AccountBinding $binding): void
    {
        if (self::$purchase===null || self::$purchase->fingerprint!==$binding->fingerprint || self::$purchase->consumed
            || (self::$purchase->reservedDay!==null && self::$purchase->reservedDay!==self::day())) { throw new EnomTestCallLimit; }
    }
    public static function purchaseUnconsumed(string $fingerprint, ?int $operationId=null): bool
    { return self::$purchase!==null&&self::$purchase->fingerprint===$fingerprint&&($operationId===null||self::$purchase->operationId===$operationId)&&self::$purchase->reservedDay!==null&&!self::$purchase->consumed; }
    /** Reserve the Purchase on its send day, immediately before sealing the paid operation. */
    public static function reservePurchase(AccountBinding $binding,?\Paymenter\Extensions\Servers\Domains\Data\Money $cost=null): void
    {
        self::assertPurchaseCurrent($binding);
        if (self::$purchase->reservedDay!==null) { throw new EnomTestCallLimit; }
        $day=$binding->environment===Environment::Live?EnomLiveCalls::reserve($binding,$cost??throw new EnomTestCallLimit):self::reserve($binding->environment);
        self::assertReservedDay($day);
        self::$purchase->day=$day;self::$purchase->reservedDay=$day;
    }
    public static function prepareSend(AccountBinding $binding, bool $purchase): string
    {
        if ($binding->environment===Environment::Live&&!$purchase) { return EnomLiveCalls::reserve($binding); }
        if ($purchase) {
            if (self::$purchase===null || self::$purchase->fingerprint!==$binding->fingerprint || self::$purchase->consumed
                || self::$purchase->reservedDay===null || self::$purchase->reservedDay!==self::day()) {
                throw new EnomTestCallLimit;
            }
            return self::$purchase->reservedDay;
        }
        $day=self::reserve($binding->environment);self::assertReservedDay($day);return $day;
    }
    public static function authorizeSend(AccountBinding $binding, bool $purchase, string $day): void
    {
        if ($binding->registrarKey!=='enom') { throw new EnomTestCallLimit; }
        self::assertReservedDay($day);
        if ($purchase) {
            if (!self::purchaseUnconsumed($binding->fingerprint)||self::$purchase->reservedDay!==$day) { throw new EnomTestCallLimit; }
            self::$purchase->consumed=true;
        }
    }
    public static function send(AccountBinding $binding, bool $purchase): void
    {
        $day=self::prepareSend($binding,$purchase);self::authorizeSend($binding,$purchase,$day);
    }
    public static function callsRemain(): bool
    {
        $limit=self::configuredLimit();if(!is_int($limit)||$limit<1){return false;}
        try{return (int)(DB::table(EnomTestCallSchema::TABLE)->where('day',self::day())->value('used')??0)<$limit;}
        catch(\Throwable){return false;}
    }
    public static function facts(): string
    {
        $limit=self::configuredLimit();
        if(!is_int($limit)||$limit<0){return 'Deployment setting domains.enom_test_daily_call_cap is invalid; all Enom TEST requests are refused. Contact the deployment administrator.';}
        try {
            $used=(int)(DB::table(EnomTestCallSchema::TABLE)->where('day',self::day())->value('used')??0);
            if($limit===0){return 'Daily limit is 0; all Enom TEST requests are refused. '.$used.' used today (UTC).';}
            $summary=$used.' / '.$limit.' used today (UTC), shared by all Enom TEST accounts. Every reserved request counts, including ones that failed, timed out or were stopped before sending.';
            return $used>=$limit?$summary.' Limit reached; Enom TEST requests are refused until 00:00 UTC or until the deployment raises the limit.':$summary;
        } catch (\Throwable) { return 'Enom TEST call counter unavailable; all Enom TEST requests are refused until it can be read.'; }
    }
    /** Staff refusal facts; explains a refusal while calls remain (counter error or UTC day change). */
    public static function refusedFacts(): string
    { return self::facts().(self::callsRemain()?' Calls remain today, so a counter error or UTC day change refused it; you can retry.':''); }
}
