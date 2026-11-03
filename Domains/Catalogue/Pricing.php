<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PricingRules};

/** F51: one business default; only a TLD may own a different rule. Retained account snapshots are ignored. */
final class Pricing
{
    public static function hasOwn(Tld $t): bool
    {
        $intent=OfferState::decode($t->catalogue_intent);
        return array_key_exists('pricing_rule',$intent)?$intent['pricing_rule']!==null:!$t->use_default_rule;
    }
    public static function own(Tld $t, ?array $fee=null): ?array
    {
        $intent=OfferState::decode($t->catalogue_intent);
        if(array_key_exists('pricing_rule',$intent)){return $intent['pricing_rule'];}
        // F51 leaves saved TLD overrides in place, including those converted before this integration.
        return $t->use_default_rule?null:(new PricingRules)->rule($t,false,$fee);
    }
    public static function rule(?Tld $t=null,?array $default=null,?array $fee=null): array
    {
        $rule=$t?self::own($t,$fee):null;
        $rule??=$default??(new PricingRules)->defaultRule(false,$fee);
        if($rule['mode']==='markup_fee_percent'){$rule['card_fee']=$fee??CardFee::read();}
        return \Paymenter\Extensions\Servers\Domains\Services\PricingRule::validate($rule);
    }
}
