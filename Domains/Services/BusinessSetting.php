<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\BusinessSettings;
/** Pricing, card fee and DNS defaults share one extension-owned row and mutex. */
final class BusinessSetting
{
    public const COLUMNS=[PricingRules::KEY=>'pricing_rule',CardFee::KEY=>'card_fee',SiteDnsDefault::KEY=>'dns_template'];
    public static function singleton(bool $lock=false): BusinessSettings
    {
        if($lock&&DB::transactionLevel()<1){throw new \LogicException('Global choice locks require a transaction.');}
        return BusinessSettings::whereKey(1)->when($lock,fn($q)=>$q->lockForUpdate())->sole();
    }
    private static function column(string $key): string
    { return self::COLUMNS[$key]??throw new \LogicException('Unknown global domain choice.'); }
    /** Preserve unreadable or encrypted legacy choices as invalid, rather than selecting a new default. */
    public static function row(string $key,bool $lock=false): ?object
    {
        $s=self::singleton($lock);$column=self::column($key);$saved=$s->$column;
        if($s->getRawOriginal($column)===null){return null;}
        if(!is_array($saved)||!is_string($saved['type']??null)||!is_bool($saved['encrypted']??null)||!array_key_exists('value',$saved)){throw new \DomainException('Saved global domain choice cannot be read.');}
        $value=$saved['value'];
        if($saved['encrypted']){$value=null;}
        elseif($saved['type']==='array'){$value=json_decode((string)$value,true);}
        return (object)['type'=>$saved['type'],'encrypted'=>(bool)$saved['encrypted'],'value'=>$value];
    }
    public static function saveLocked(string $key,array|string $value): void
    {
        if(DB::transactionLevel()<1){throw new \LogicException('Global choices require an outer transaction.');}
        $s=self::singleton(true);$s->{self::column($key)}=['type'=>is_array($value)?'array':'string','encrypted'=>false,'value'=>is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):$value];$s->save();
    }
    /** What every pricing review reads. DNS changes do not invalidate a pricing review. */
    public static function stamp(bool $lock=false): array
    {
        $s=self::singleton($lock);return ['pricing_rule'=>$s->pricing_rule,'card_fee'=>$s->card_fee];
    }
}
