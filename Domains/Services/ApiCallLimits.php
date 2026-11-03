<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Our runaway guard. One native setting per environment/login, shared by all server rows using that login. */
final class ApiCallLimits
{
    // Matches the existing TEST physical guard's upper bound. Staff cannot turn either guard off or remove its ceiling.
    public const MAX=100000;
    private static function query(Environment $environment,string $uid): Builder
    {
        // Extension-owned morph scope, with no native server lifecycle or global settings cache dependency.
        return Setting::where('settingable_type',self::class)->where('settingable_id',0)
            ->where('key','domains_api_call_limits.'.$environment->value.'.'.hash('sha256',strtolower($uid)));
    }
    /** Config remains the default until a login has a saved override. Invalid saved state fails closed. */
    public static function read(Environment $environment,string $uid): array
    {
        $test=$environment===Environment::Test;
        $day=$test?config(EnomTestSendLimit::PER_DAY,1000):(config('domains.enom_live.accounts',[])[$uid]['daily_call_cap']??0);
        $minute=$test?config(EnomTestSendLimit::PER_MINUTE,60):null;
        $defaults=['day'=>is_int($day)&&$day>=0&&(!$test||$day<=self::MAX)?$day:null,
            'minute'=>$test&&is_int($minute)&&$minute>=0&&$minute<=self::MAX?$minute:null];
        if($uid===''){return $defaults;}
        try{
            $rows=self::query($environment,$uid)->get();
            if($rows->isEmpty()){return $defaults;}
            if($rows->count()!==1){throw new \DomainException;}
            $saved=json_decode($rows->sole()->value,true,4,JSON_THROW_ON_ERROR);
            if(!is_array($saved)||array_diff(array_keys($saved),$test?['day','minute']:['day'])){throw new \DomainException;}
            foreach($test?['day','minute']:['day'] as $key){if(!is_int($saved[$key]??null)||$saved[$key]<1||$saved[$key]>self::MAX){throw new \DomainException;}}
            return ['day'=>$saved['day'],'minute'=>$test?$saved['minute']:null];
        }catch(\Throwable){return ['day'=>null,'minute'=>null];}
    }
    public static function forAccount(Account $a): ?array
    {
        if($a->registrar_key!=='enom'){return null;}
        try{return self::read(Environment::from($a->environment),(string)$a->stable_identity);}
        catch(\Throwable){return ['day'=>null,'minute'=>null];}
    }
    private static function number(mixed $value): int
    {
        if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]{0,5}$/D',(string)$value)||(int)$value>self::MAX){
            throw new \DomainException('Enter a whole number from 1 to '.self::MAX.'.');
        }
        return (int)$value;
    }
    public static function save(StaffSettingsReview $r,array $data): void
    {
        $r->local(static function()use($r,$data):void {
            if($r->action!=='call_limits'||$r->tldId!==null){throw new \DomainException('Account call limit review required.');}
            $a=Account::where('server_id',$r->serverId)->sole();
            if($a->registrar_key!=='enom'){throw new \DomainException('Enom account required.');}
            $environment=Environment::from($a->environment);$uid=(string)$a->stable_identity;
            if($uid===''){throw new \DomainException('Fix the API connection first.');}
            $value=['day'=>self::number($data['day']??null)];
            if($environment===Environment::Test){$value['minute']=self::number($data['minute']??null);}
            if(self::forAccount($a)===['day'=>$value['day'],'minute'=>$value['minute']??null]){throw new \DomainException('Call limits unchanged.');}
            $row=self::query($environment,$uid)->lockForUpdate()->first()??new Setting([
                'settingable_type'=>self::class,'settingable_id'=>0,'key'=>'domains_api_call_limits.'.$environment->value.'.'.hash('sha256',strtolower($uid))]);
            $row->value=json_encode($value,JSON_THROW_ON_ERROR);$row->type='string';$row->encrypted=false;$row->save();
        });
    }
}
