<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
/**
 * The single-request Purchase scope and the typed "refused before sending" path.
 * EnomTestSendLimit counts TEST sends; EnomLiveCalls reserves LIVE days.
 */
final class EnomTestCalls
{
    private static ?object $purchase=null;
    private static int $refusalGeneration=0;
    private static string $refusalReason='send_check';
    public static function day(): string
    { return app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'); }
    /** Lets the registry restore a typed refusal across the verifier's deliberate error redaction. */
    public static function refusalGeneration(): int { return self::$refusalGeneration; }
    public static function refuse(string $reason='send_check'): never {++self::$refusalGeneration;self::$refusalReason=$reason;throw new EnomTestCallLimit($reason);}
    public static function refusalReason(): string { return self::$refusalReason; }
    public static function refusedSince(int $generation): bool { return self::$refusalGeneration!==$generation; }
    /** A LIVE reservation is valid only on its UTC day. TEST scopes are armed with '' and have no day. */
    public static function assertReservedDay(string $day): void
    { if ($day!==''&&$day!==self::day()) { ++self::$refusalGeneration;throw new EnomTestCallLimit; } }
    /** Open a single-request Purchase scope; it is armed after preflight and before seal. */
    public static function withPurchase(AccountBinding $binding, \Closure $work, ?int $operationId=null,string $action='registration'): mixed
    {
        if ($binding->registrarKey!=='enom' || !in_array($action,['registration','transfer'],true) || ($binding->environment!==Environment::Test&&!($action==='transfer'?EnomTestTransfer::accepted($binding):EnomLive::enabled($binding,'registration'))) || ($operationId!==null&&$operationId<1)) { throw new EnomTestCallLimit; }
        if (self::$purchase!==null) { throw new EnomTestCallLimit; }
        self::$purchase=(object)['fingerprint'=>$binding->fingerprint,'operationId'=>$operationId,'reservedDay'=>null,'consumed'=>false];
        try { return $work(); } finally { self::$purchase=null; }
    }
    private static function current(?string $day): bool { return $day===null||$day===''||$day===self::day(); }
    public static function assertPurchaseCurrent(AccountBinding $binding): void
    {
        if (self::$purchase===null || self::$purchase->fingerprint!==$binding->fingerprint || self::$purchase->consumed
            || !self::current(self::$purchase->reservedDay)) { throw new EnomTestCallLimit; }
    }
    /** The unsent proof: armed for this operation and not yet handed to cURL. */
    public static function purchaseUnconsumed(string $fingerprint, ?int $operationId=null): bool
    { return self::$purchase!==null&&self::$purchase->fingerprint===$fingerprint&&($operationId===null||self::$purchase->operationId===$operationId)&&self::$purchase->reservedDay!==null&&!self::$purchase->consumed; }
    /** Arm the Purchase immediately before sealing the paid operation. LIVE also reserves its call and spend. */
    public static function reservePurchase(AccountBinding $binding,?\Paymenter\Extensions\Servers\Domains\Data\Money $cost=null): void
    {
        self::assertPurchaseCurrent($binding);
        if (self::$purchase->reservedDay!==null) { throw new EnomTestCallLimit; }
        $day=$binding->environment===Environment::Live?EnomLiveCalls::reserve($binding,$cost??throw new EnomTestCallLimit):'';
        self::assertReservedDay($day);
        self::$purchase->reservedDay=$day;
    }
    public static function prepareSend(AccountBinding $binding, bool $purchase): string
    {
        if (!$purchase) { return $binding->environment===Environment::Live?EnomLiveCalls::reserve($binding):''; }
        if (self::$purchase===null || self::$purchase->fingerprint!==$binding->fingerprint || self::$purchase->consumed
            || self::$purchase->reservedDay===null || !self::current(self::$purchase->reservedDay)) {
            throw new EnomTestCallLimit;
        }
        return self::$purchase->reservedDay;
    }
    /** Called after EnomTestSendLimit admits the send; only curl_exec follows. */
    public static function authorizeSend(AccountBinding $binding, bool $purchase, string $day): void
    {
        if ($binding->registrarKey!=='enom') { throw new EnomTestCallLimit; }
        self::assertReservedDay($day);
        if ($purchase) {
            if (!self::purchaseUnconsumed($binding->fingerprint)||self::$purchase->reservedDay!==$day) { throw new EnomTestCallLimit; }
            self::$purchase->consumed=true;
        }
    }
}
