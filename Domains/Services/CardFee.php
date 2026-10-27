<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
/** One estimated card fee per payment; all arithmetic uses integers. */
final class CardFee
{
    public const DEFAULT=['percent'=>'2.90','fixed'=>'0.30'];
    public const KEY='domains_card_fee';
    public static function validate(array $fee): array
    {
        $percent=PricingRule::units((string)($fee['percent']??''),2);
        $fixed=PricingRule::units((string)($fee['fixed']??''),2);
        if($percent>=10000||$fixed>10000000){throw new \InvalidArgumentException('Enter a card fee below 100% and a fixed amount from 0 to 100000.');}
        return ['percent'=>PricingRule::decimal($percent),'fixed'=>PricingRule::decimal($fixed)];
    }
    public static function describe(array $fee): string
    {
        $fee=self::validate($fee);
        return rtrim(rtrim($fee['percent'],'0'),'.').'% + $'.$fee['fixed'];
    }
    public static function read(int $serverId,bool $lock=false): array
    {
        if($lock&&DB::transactionLevel()===0){throw new \LogicException('Card fee locks require an outer transaction.');}
        $q=Server::whereKey($serverId);if($lock){$q->lockForUpdate();}$s=$q->firstOrFail();
        $q=$s->settings()->where('key',self::KEY);if($lock){$q->lockForUpdate();}$setting=$q->first();
        $fee=$setting?$setting->value:self::DEFAULT;
        if(!is_array($fee)){throw new \DomainException('Invalid card fee setting.');}
        return self::validate($fee);
    }
    public static function saveLocked(int $serverId,array $fee): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Outer card fee transaction required.');}
        $s=Server::whereKey($serverId)->lockForUpdate()->firstOrFail();
        $s->settings()->updateOrCreate(['key'=>self::KEY],['type'=>'array','encrypted'=>false,'value'=>self::validate($fee)]);
        StorefrontCatalogueCache::invalidateAfterCommit();
    }
    /** Smallest cent price covering cost and the fee on that price, with markup on both. */
    public static function minimum(string $cost,string $markup,array $fee): int
    {
        $fee=self::validate($fee);$factor=10000+PricingRule::units($markup,2);
        $denominator=100000000-PricingRule::units($fee['percent'],2)*$factor;
        if($denominator<=0){throw new \InvalidArgumentException('This percentage cannot cover the card fee. Lower the markup or card fee.');}
        // ceil(100 * (cost in 1/10000 + fixed fee in 1/10000) * factor / denominator).
        // Split before multiplying: even schema-limit costs and markups never become floats.
        $base=100*(PricingRule::units($cost)+PricingRule::units($fee['fixed'],2)*100);
        $whole=intdiv($base,$denominator);$fraction=($base%$denominator)*$factor;
        $limit=999999999999;
        if($whole>intdiv($limit,$factor)){throw new \InvalidArgumentException('The calculated price is too large.');}
        $minor=$whole*$factor+intdiv($fraction,$denominator)+($fraction%$denominator?1:0);
        if($minor>$limit){throw new \InvalidArgumentException('The calculated price is too large.');}
        return $minor;
    }
    /** Profit at 4 decimal places; the estimate rounds the card fee up to 1/10000. */
    public static function profit(string $retail,string $cost,array $fee): string
    {
        $fee=self::validate($fee);$minor=PricingRule::units($retail,2);
        $product=$minor*PricingRule::units($fee['percent'],2);
        $feeUnits=intdiv($product,100)+($product%100?1:0)+PricingRule::units($fee['fixed'],2)*100;
        return PricingRule::decimal($minor*100-PricingRule::units($cost)-$feeUnits,4);
    }
    public static function losesMoney(string $retail,string $cost,array $fee): bool
    {return str_starts_with(self::profit($retail,$cost,$fee),'-');}
}
