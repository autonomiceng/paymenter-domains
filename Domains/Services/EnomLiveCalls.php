<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,Money};
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Daily UTC reservations. Failed and unsent attempts retain both call and spend reservations. */
final class EnomLiveCalls
{
    private static function micros(string $amount): int
    {
        if(!preg_match('/^(?:0|[1-9][0-9]{0,8})\.[0-9]{1,6}$/D',$amount)){throw new EnomTestCallLimit;}
        [$whole,$fraction]=explode('.',$amount);return (int)$whole*1000000+(int)str_pad($fraction,6,'0');
    }
    public static function reserve(AccountBinding $b,?Money $cost=null): string
    {
        OperationClaims::outside();
        try {
            $row=EnomLive::account($b);$limit=$row['daily_call_cap']??0;$spend=$row['daily_spend_limit_usd']??'0.00';
            if(!is_int($limit)||$limit<1||!is_string($spend)||$cost!==null&&$cost->currency!=='USD'){throw new EnomTestCallLimit;}
            $ceiling=self::micros($spend);$amount=$cost?self::micros($cost->amount):0;
            if($amount>$ceiling){throw new EnomTestCallLimit;}
            $day=EnomTestCalls::day();
            // The UID retains usage if a later deployment selects another server.
            $uid=Account::where('server_id',$b->serverId)->sole()->stable_identity;$key=hash('sha256',$uid);
            DB::table(EnomLiveCallSchema::TABLE)->insertOrIgnore(['account'=>$key,'day'=>$day,'used'=>0,'spend_micros'=>0]);
            if(DB::table(EnomLiveCallSchema::TABLE)->where(['account'=>$key,'day'=>$day])->where('used','<',$limit)
                ->where('spend_micros','<=',$ceiling-$amount)->update(['used'=>DB::raw('used + 1'),'spend_micros'=>DB::raw('spend_micros + '.$amount)])!==1){throw new EnomTestCallLimit;}
            EnomTestCalls::assertReservedDay($day);return $day;
        }catch(\Throwable){EnomTestCalls::refuse();}
    }
    public static function facts(AccountBinding $b): string
    {
        try {
            $config=EnomLive::account($b);$uid=Account::where('server_id',$b->serverId)->sole()->stable_identity;
            $row=DB::table(EnomLiveCallSchema::TABLE)->where(['account'=>hash('sha256',$uid),'day'=>EnomTestCalls::day()])->first();
            $micros=(int)($row->spend_micros??0);$amount=intdiv($micros,1000000).'.'.str_pad((string)($micros%1000000),6,'0',STR_PAD_LEFT);
            return ($row->used??0).' / '.($config['daily_call_cap']??0).' calls; '.$amount.' / '.($config['daily_spend_limit_usd']??'0.00').' USD reserved today (UTC). Failed and unsent reservations remain counted.';
        }catch(\Throwable){return 'Live account limits unavailable; sends are refused.';}
    }
    public static function read(string $uid): string
    {
        // Identity-only deployments retain their existing read-only behavior.
        if(!EnomLive::requested()){return '';}
        $uid=strtolower($uid);
        foreach(Account::where('registrar_key','enom')->where('environment','live')->get() as $a){
            try{$binding=CatalogueAcceptances::binding($a);if($a->stable_identity===$uid&&EnomLive::enabled($binding,'catalogue')){return self::reserve($binding);}}catch(\Throwable){continue;}
        }
        EnomTestCalls::refuse();
    }
}
