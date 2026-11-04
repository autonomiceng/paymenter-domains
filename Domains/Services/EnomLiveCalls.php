<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Money};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\EnomLiveReservationPolicy;

/** Enom transport compatibility, including the existing typed definitely-unsent refusal. */
final class EnomLiveCalls
{
    private static function reservations(): LiveReservations {return new LiveReservations(new EnomLiveReservationPolicy);}
    public static function withCheckoutHeadroom(string $uid,\Closure $read): mixed
    {return LiveReservations::withCheckoutHeadroom('enom',$uid,$read);}
    public static function reserve(AccountBinding $b,?Money $cost=null): string
    {
        OperationClaims::outside();
        try{return self::reservations()->reserve($b,$cost);}catch(\Throwable){EnomTestCalls::refuse();}
    }
    public static function facts(AccountBinding $b): string
    {
        try {
            $config=EnomLive::account($b);$row=self::reservations()->usage($b);
            $micros=$row['spend_micros'];$amount=intdiv($micros,1000000).'.'.str_pad((string)($micros%1000000),6,'0',STR_PAD_LEFT);
            return $row['used'].' / '.($config['daily_call_cap']??0).' calls under our daily call cap; '.$amount.' / '.($config['daily_spend_limit_usd']??'0.00').' USD reserved today (UTC). Failed and unsent reservations remain counted.';
        }catch(\Throwable){return 'Our live account safety limits could not be read; sends are refused.';}
    }
    public static function read(string $uid): string
    {
        // Identity-only deployments retain their existing read-only behavior.
        if(!EnomLive::requested()){return '';}
        $uid=strtolower($uid);
        foreach(Account::where('registrar_key','enom')->where('environment','live')->get() as $a){
            try{$binding=AccountBindings::binding($a);if($a->stable_identity===$uid&&EnomLive::enabled($binding,'catalogue')){return self::reserve($binding);}}catch(\Throwable){continue;}
        }
        EnomTestCalls::refuse();
    }
}
