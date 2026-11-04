<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,LiveReservationPolicy};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,Money};
use Paymenter\Extensions\Servers\Domains\Models\Account;

/** Daily UTC call/spend reservations. Failed and unsent attempts remain counted; no release or retry authority. */
final class LiveReservations
{
    private static array $checkoutHeadroom=[];
    public function __construct(private readonly LiveReservationPolicy $policy,private readonly Environment $environment=Environment::Live) {}

    private static function key(string $provider,#[\SensitiveParameter] string $identity,Environment $environment=Environment::Live): string
    {
        $identity=AccountBinding::canonicalIdentity($provider,$identity);
        // Keep deployed Enom keys exactly; all other providers get an independent namespace.
        return hash('sha256',$provider==='enom'&&$environment===Environment::Live?$identity:$provider."\0".$environment->value."\0".$identity);
    }
    private function account(AccountBinding $binding): string
    {
        if($binding->environment!==$this->environment||$this->environment===Environment::Test&&$binding->registrarKey!=='namesilo'){throw new LiveReservationLimit;}
        $account=Account::where('server_id',$binding->serverId)->sole();
        $binding->requireMatch(AccountBindings::binding($account));
        return self::key($binding->registrarKey,$account->stable_identity,$this->environment);
    }
    /** Narrow this consumer's atomic daily share; default background work keeps half for checkout. */
    public static function withCheckoutHeadroom(string $provider,#[\SensitiveParameter] string $identity,\Closure $read,int $dailyPercent=50,Environment $environment=Environment::Live): mixed
    {
        if($dailyPercent<1||$dailyPercent>50){throw new \InvalidArgumentException('Invalid daily read share.');}
        $account=self::key($provider,$identity,$environment);$prior=self::$checkoutHeadroom[$account]??null;
        self::$checkoutHeadroom[$account]=min($prior??100,$dailyPercent);
        try{return $read();}finally{if($prior===null){unset(self::$checkoutHeadroom[$account]);}else{self::$checkoutHeadroom[$account]=$prior;}}
    }
    public static function day(): string
    {return app(Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');}
    private static function micros(string $amount): int
    {
        if(!preg_match('/^(?:0|[1-9][0-9]{0,8})\.[0-9]{1,6}$/D',$amount)){throw new LiveReservationLimit;}
        [$whole,$fraction]=explode('.',$amount);return (int)$whole*1000000+(int)str_pad($fraction,6,'0');
    }
    public function reserve(AccountBinding $binding,?Money $cost=null): string
    {
        try {
            OperationClaims::outside();
            $row=$this->policy->limits($binding);$limit=$row['daily_call_cap']??0;$spend=$row['daily_spend_limit_usd']??'0.00';
            if(!is_int($limit)||$limit<1||!is_string($spend)||$cost!==null&&$cost->currency!=='USD'){throw new LiveReservationLimit;}
            $ceiling=self::micros($spend);$amount=$cost?self::micros($cost->amount):0;
            if($amount>$ceiling){throw new LiveReservationLimit;}
            $day=self::day();$key=$this->account($binding);
            if(isset(self::$checkoutHeadroom[$key])){$share=self::$checkoutHeadroom[$key];$limit=intdiv($limit,100)*$share+intdiv(($limit%100)*$share,100);}
            DB::table(LiveReservationSchema::TABLE)->insertOrIgnore(['account'=>$key,'day'=>$day,'used'=>0,'spend_micros'=>0]);
            if(DB::table(LiveReservationSchema::TABLE)->where(['account'=>$key,'day'=>$day])->where('used','<',$limit)
                ->where('spend_micros','<=',$ceiling-$amount)->update(['used'=>DB::raw('used + 1'),'spend_micros'=>DB::raw('spend_micros + '.$amount)])!==1){throw new LiveReservationLimit;}
            if($day!==self::day()){throw new LiveReservationLimit;}return $day;
        }catch(\Throwable){throw new LiveReservationLimit;}
    }
    /** @return array{used:int,spend_micros:int} Current counters, with no write or new send authority. */
    public function usage(AccountBinding $binding): array
    {
        try {
            $this->policy->limits($binding);
            $row=DB::table(LiveReservationSchema::TABLE)->where(['account'=>$this->account($binding),'day'=>self::day()])->first();
            return ['used'=>(int)($row->used??0),'spend_micros'=>(int)($row->spend_micros??0)];
        }catch(\Throwable){throw new LiveReservationLimit;}
    }
}
