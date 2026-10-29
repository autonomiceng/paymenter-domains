<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
/** Our deployment-wide TEST safety reservations; the default daily cap is 50. Independent commits precede send; never refund a unit. */
final class EnomTestCalls
{
    private static ?object $purchase=null;
    private static int $refusalGeneration=0;
    private static function configuredLimit(): mixed { return config('domains.enom_test_daily_call_cap',50); }
    public static function limit(): int
    { if(EnomTestRunBudget::enabled()){try{return EnomTestRunBudget::status()['cap'];}catch(\Throwable){return 0;}}$v=self::configuredLimit();return is_int($v)&&$v>=0?$v:0; }
    public static function day(): string
    { return app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'); }
    /** Lets the registry restore a typed refusal across the verifier's deliberate error redaction. */
    public static function refusalGeneration(): int { return self::$refusalGeneration; }
    public static function refuse(): never {++self::$refusalGeneration;throw new EnomTestCallLimit;}
    public static function refusedSince(int $generation): bool { return self::$refusalGeneration!==$generation; }
    public static function reserve(Environment $environment): string
    {
        if ($environment!==Environment::Test) { return ''; }
        if (EnomTestRunBudget::enabled()) { return EnomTestRunBudget::reserve(); }
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
    /** Open a single-request Purchase scope; its unit is reserved after preflight and before seal. */
    public static function withPurchase(AccountBinding $binding, \Closure $work, ?int $operationId=null): mixed
    {
        if ($binding->registrarKey!=='enom' || ($binding->environment!==Environment::Test&&!EnomLive::enabled($binding,'registration')) || ($operationId!==null&&$operationId<1)) { throw new EnomTestCallLimit; }
        if (self::$purchase!==null) { throw new EnomTestCallLimit; }
        self::$purchase=(object)['fingerprint'=>$binding->fingerprint,'operationId'=>$operationId,'day'=>null,'reservedDay'=>null,'consumed'=>false,'runPermit'=>$binding->environment===Environment::Test&&EnomTestRunBudget::enabled()];
        try { return $work(); } finally { self::$purchase=null; }
    }
    public static function assertPurchaseCurrent(AccountBinding $binding): void
    {
        if (self::$purchase===null || self::$purchase->fingerprint!==$binding->fingerprint || self::$purchase->consumed
            || (!self::$purchase->runPermit && self::$purchase->reservedDay!==null && self::$purchase->reservedDay!==self::day())) { throw new EnomTestCallLimit; }
    }
    public static function purchaseUnconsumed(string $fingerprint, ?int $operationId=null): bool
    { return self::$purchase!==null&&self::$purchase->fingerprint===$fingerprint&&($operationId===null||self::$purchase->operationId===$operationId)&&self::$purchase->reservedDay!==null&&!self::$purchase->consumed; }
    /** Reserve the Purchase immediately before sealing the paid operation. */
    public static function reservePurchase(AccountBinding $binding,?\Paymenter\Extensions\Servers\Domains\Data\Money $cost=null): void
    {
        self::assertPurchaseCurrent($binding);
        if (self::$purchase->reservedDay!==null) { throw new EnomTestCallLimit; }
        $day=self::$purchase->runPermit?EnomTestRunBudget::reserve($binding->fingerprint,'purchase',self::$purchase->operationId)
            :($binding->environment===Environment::Live?EnomLiveCalls::reserve($binding,$cost??throw new EnomTestCallLimit):self::reserve($binding->environment));
        if(!self::$purchase->runPermit){self::assertReservedDay($day);}
        self::$purchase->day=$day;self::$purchase->reservedDay=$day;
    }
    public static function prepareSend(AccountBinding $binding, bool $purchase): string
    {
        if ($binding->environment===Environment::Live&&!$purchase) { return EnomLiveCalls::reserve($binding); }
        if ($purchase) {
            if (self::$purchase===null || self::$purchase->fingerprint!==$binding->fingerprint || self::$purchase->consumed
                || self::$purchase->reservedDay===null || !self::$purchase->runPermit&&self::$purchase->reservedDay!==self::day()) {
                throw new EnomTestCallLimit;
            }
            if(self::$purchase->runPermit){EnomTestRunBudget::assertPermit(self::$purchase->reservedDay,$binding->fingerprint,'purchase',self::$purchase->operationId);}
            return self::$purchase->reservedDay;
        }
        if($binding->environment===Environment::Test&&EnomTestRunBudget::enabled()){return EnomTestRunBudget::reserve($binding->fingerprint);}
        $day=self::reserve($binding->environment);self::assertReservedDay($day);return $day;
    }
    public static function authorizeSend(AccountBinding $binding, bool $purchase, string $day): void
    {
        if ($binding->registrarKey!=='enom') { throw new EnomTestCallLimit; }
        $runPermit=$binding->environment===Environment::Test&&EnomTestRunBudget::enabled();
        if(!$runPermit){self::assertReservedDay($day);}
        if ($purchase) {
            if (!self::purchaseUnconsumed($binding->fingerprint)||self::$purchase->reservedDay!==$day) { throw new EnomTestCallLimit; }
            if($runPermit){EnomTestRunBudget::consume($day,$binding->fingerprint,'purchase',self::$purchase->operationId);}
            self::$purchase->consumed=true;
        }elseif($runPermit){EnomTestRunBudget::consume($day,$binding->fingerprint,'read');}
    }
    public static function prepareRead(Environment $environment,string $uid,string $command='GetBalance'): string
    { return $environment===Environment::Test&&EnomTestRunBudget::enabled()?EnomTestRunBudget::reserveRead($uid,$command):self::reserve($environment); }
    public static function authorizeRead(Environment $environment,string $reservation): void
    { if($environment===Environment::Test&&EnomTestRunBudget::enabled()){EnomTestRunBudget::consume($reservation,action:'read');}else{self::assertReservedDay($reservation);} }
    public static function send(AccountBinding $binding, bool $purchase): void
    {
        $day=self::prepareSend($binding,$purchase);self::authorizeSend($binding,$purchase,$day);
    }
    public static function callsRemain(): bool
    {
        if(EnomTestRunBudget::enabled()){try{EnomTestRunBudget::assertCapacity(1);return true;}catch(\Throwable){return false;}}
        $limit=self::configuredLimit();if(!is_int($limit)||$limit<1){return false;}
        try{return (int)(DB::table(EnomTestCallSchema::TABLE)->where('day',self::day())->value('used')??0)<$limit;}
        catch(\Throwable){return false;}
    }
    /** Today's count and limit for the Registrars page; null when the setting is invalid or the counter can't be read.
     * @return array{used:?int,limit:?int} */
    public static function usage(): array
    {
        if(EnomTestRunBudget::enabled()){try{$status=EnomTestRunBudget::status();return $status+['limit'=>$status['cap']];}catch(\Throwable){return ['used'=>null,'limit'=>null];}}
        $limit=self::configuredLimit();
        try{$used=(int)(DB::table(EnomTestCallSchema::TABLE)->where('day',self::day())->value('used')??0);}catch(\Throwable){$used=null;}
        return ['used'=>$used,'limit'=>is_int($limit)&&$limit>=0?$limit:null];
    }
    public static function facts(): string
    {
        if(EnomTestRunBudget::enabled()){try{$s=EnomTestRunBudget::status();return $s['used'].' / '.$s['cap'].' requests used in TEST run '.$s['run_id'].'. '.$s['remaining'].' credits remain. Phase: '.$s['phase'].'. Failed attempts remain counted.';}catch(\Throwable){return 'Our Enom TEST safety run budget is unavailable; requests are refused.';}}
        $limit=self::configuredLimit();
        if(!is_int($limit)||$limit<0){return 'Deployment setting domains.enom_test_daily_call_cap is invalid; all Enom TEST requests are refused. Contact the deployment administrator.';}
        try {
            $used=(int)(DB::table(EnomTestCallSchema::TABLE)->where('day',self::day())->value('used')??0);
            if($limit===0){return 'Our daily safety call cap is 0; all Enom TEST requests are refused. '.$used.' used today (UTC).';}
            $summary=$used.' / '.$limit.' used today (UTC), shared by all Enom TEST accounts. Every reserved request counts, including ones that failed, timed out or were stopped before sending.';
            return $used>=$limit?$summary.' Our safety call cap is reached; Enom TEST requests are refused until 00:00 UTC or until the deployment raises the limit.':$summary;
        } catch (\Throwable) { return 'Enom TEST call counter unavailable; all Enom TEST requests are refused until the counter can be read and updated. Ask the deployment administrator to repair it.'; }
    }
    /** Staff refusal facts; explains a refusal while calls remain (counter error or UTC day change). */
    public static function refusedFacts(): string
    { if(EnomTestRunBudget::enabled()){return self::facts().' Check the active run policy, account and remaining credits.';}return self::facts().(self::callsRemain()?' Calls remain today, but a counter error or UTC day change refused the request. Ask the deployment administrator to repair the counter if the refusal continues after the UTC day changes.':''); }
}
