<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\Money;
/** Verified account/TLD policy and separate registrar charges. No spending authority. */
final class LifecycleFacts
{
    public const MAX_AGE=30*86400;
    public const FIELDS=['grace_days','restore_days','grace_supported','restore_supported','auto_renew_only','conflicting',
        'verified_at','source','grace_extra','grace_currency','grace_verified_at','grace_source',
        'restore_fee','restore_currency','restore_verified_at','restore_source'];
    public static function validate(array $v): array
    {
        if(array_diff(self::FIELDS,array_keys($v))||array_diff(array_keys($v),self::FIELDS)){
            throw new \DomainException('Complete lifecycle facts required.');
        }
        foreach(['grace_supported','restore_supported','auto_renew_only','conflicting'] as $key){
            if(!is_bool($v[$key])){throw new \DomainException('Invalid lifecycle support.');}
        }
        foreach(['grace_days','restore_days'] as $key){
            if(!is_int($v[$key])||$v[$key]<0||$v[$key]>365){throw new \DomainException('Lifecycle days must be between 0 and 365.');}
        }
        foreach(['','grace_','restore_'] as $prefix){
            self::instant($v[$prefix.'verified_at']);
            $source=$v[$prefix.'source'];
            if(!is_string($source)||$source!==trim($source)||!StaffReason::valid($source,5,500)||preg_match('/[\x00-\x1f\x7f]/',$source)){
                throw new \DomainException('Enter a one-line lifecycle source between 5 and 500 characters.');
            }
        }
        foreach(['grace'=>'grace_extra','restore'=>'restore_fee'] as $prefix=>$amount){
            if(!is_string($v[$amount])||($normalized=PricingRule::wholesale($v[$amount]))===null){throw new \DomainException('Invalid lifecycle charge.');}
            $currency=$v[$prefix.'_currency'];
            if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)){throw new \DomainException('Invalid lifecycle currency.');}
            $v[$amount]=$normalized;
        }
        return $v;
    }
    private static function instant(mixed $value): \DateTimeImmutable
    {
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D',$value)){
            throw new \DomainException('Lifecycle verification requires a date and time with UTC offset.');
        }
        try{$at=new \DateTimeImmutable($value);$errors=\DateTimeImmutable::getLastErrors();}
        catch(\Exception){throw new \DomainException('Invalid lifecycle verification time.');}
        if($errors!==false&&($errors['warning_count']||$errors['error_count'])){throw new \DomainException('Invalid lifecycle verification time.');}
        return $at;
    }
    public static function until(array $v,string $action,\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if(!in_array($action,['grace','restore'],true)){return null;}
        try{
            $v=self::validate($v);
            if($v['conflicting']||!$v[$action.'_supported']||$v[$action.'_days']===0
                ||($action==='grace'&&$v['auto_renew_only'])||$v[$action.'_currency']!=='USD'){return null;}
            $policy=self::instant($v['verified_at']);$fee=self::instant($v[$action.'_verified_at']);
            $until=min($policy,$fee)->modify('+'.self::MAX_AGE.' seconds');
            return $policy<=$now&&$fee<=$now&&$until>$now?$until:null;
        }catch(\DomainException){return null;}
    }
    /** Preserve the published annual renewal markup, including fixed-price and rounded rules. */
    public static function price(string $annualNet,Money $renewalCost,Money $extra): Money
    {
        if($renewalCost->currency!=='USD'||$extra->currency!==$renewalCost->currency){throw new \DomainException('Matching USD lifecycle costs required.');}
        $base=PricingRule::units($renewalCost->amount);$fee=PricingRule::units($extra->amount);$retail=PricingRule::units($annualNet,2);
        if($base===0||$retail===0||!PricingRule::covers($annualNet,$renewalCost->amount)){throw new \DomainException('Lifecycle pricing requires a known renewal margin that covers cost.');}
        // Integer decimal arithmetic preserves the ratio at the largest supported amounts.
        $numerator=bcmul((string)($base+$fee),(string)$retail,0);
        $minor=bcdiv($numerator,(string)$base,0);
        if(bcmod($numerator,(string)$base)!=='0'){$minor=bcadd($minor,'1',0);}
        if(bccomp($minor,'999999999999',0)>0){throw new \DomainException('Lifecycle price is too large.');}
        return new Money(PricingRule::decimal((int)$minor),'USD');
    }
}
