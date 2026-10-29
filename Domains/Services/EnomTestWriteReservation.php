<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
/** Worker-local reservation for exactly one named mutation, before the durable seal. */
final class EnomTestWriteReservation
{
    private static ?object $scope=null;
    public static function run(AccountBinding $b,string $action,int $id,\Closure $work): mixed
    {
        if(self::$scope!==null&&self::$scope->fingerprint===$b->fingerprint&&self::$scope->action===$action&&self::$scope->id===$id){return $work();}
        if(self::$scope!==null||$b->registrarKey!=='enom'||($b->environment!==Environment::Test&&!EnomLive::enabled($b,$action))||$id<1||!in_array($action,['renewal','management'],true)){throw new EnomTestCallLimit;}
        self::$scope=(object)['fingerprint'=>$b->fingerprint,'action'=>$action,'id'=>$id,'day'=>null,'consumed'=>false,'runPermit'=>$b->environment===Environment::Test&&EnomTestRunBudget::enabled()];
        try{return $work();}finally{self::$scope=null;}
    }
    public static function active(AccountBinding $b,string $action): bool
    { return self::$scope!==null&&self::$scope->fingerprint===$b->fingerprint&&self::$scope->action===$action; }
    public static function unconsumed(AccountBinding $b,string $action,int $id): bool
    { return self::active($b,$action)&&self::$scope->id===$id&&self::$scope->day!==null&&!self::$scope->consumed; }
    public static function reserve(AccountBinding $b,string $action,?\Paymenter\Extensions\Servers\Domains\Data\Money $cost=null): void
    {
        if(self::$scope===null){return;}
        self::requireScope($b,$action);
        if(self::$scope->day!==null){throw new EnomTestCallLimit;}
        self::$scope->day=self::$scope->runPermit?EnomTestRunBudget::reserve($b->fingerprint,$action,self::$scope->id)
            :($b->environment===Environment::Live?EnomLiveCalls::reserve($b,$cost):EnomTestCalls::reserve($b->environment));
        if(!self::$scope->runPermit){EnomTestCalls::assertReservedDay(self::$scope->day);}
    }
    private static function requireScope(AccountBinding $b,string $action): void
    {
        if(self::$scope===null||self::$scope->fingerprint!==$b->fingerprint||self::$scope->action!==$action||self::$scope->consumed){throw new EnomTestCallLimit;}
    }
    public static function prepare(AccountBinding $b,string $action): ?string
    {
        if(self::$scope===null){return null;}
        self::requireScope($b,$action);
        if(self::$scope->day===null){throw new EnomTestCallLimit;}
        if(self::$scope->runPermit){
            // The financial seal checks only its original worker-local reservation.
            // Recheck durable authority outside those locks, immediately before sending.
            if(\Illuminate\Support\Facades\DB::transactionLevel()===0){EnomTestRunBudget::assertPermit(self::$scope->day,$b->fingerprint,$action,self::$scope->id);}
        }
        else{EnomTestCalls::assertReservedDay(self::$scope->day);}return self::$scope->day;
    }
    public static function consume(AccountBinding $b,string $action,string $day): void
    {
        if(self::prepare($b,$action)!==$day){throw new EnomTestCallLimit;}
        if(self::$scope->runPermit){EnomTestRunBudget::consume($day,$b->fingerprint,$action,self::$scope->id);}
        self::$scope->consumed=true;
    }
}
