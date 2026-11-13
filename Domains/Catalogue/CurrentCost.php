<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
/** The current normalized cost is stored in price columns; retail is independently published. */
final class CurrentCost
{
    public static function read(object $p): ?Cost
    {
        if($p->cost_enabled===null){return null;}
        $amount=$p->wholesale===null?null:OfferState::money($p->wholesale,4);
        if($p->wholesale!==null&&$amount===null){throw new \DomainException('Invalid current cost.');}
        return new Cost($p->action,(int)$p->term,(bool)$p->cost_enabled,$amount,CostUnit::from($p->cost_source_unit),$p->source,
            new \DateTimeImmutable((string)$p->observed_at,new \DateTimeZone('UTC')),
            new \DateTimeImmutable((string)$p->fresh_until,new \DateTimeZone('UTC')),$p->currency,normalized:true);
    }
    public static function values(Cost $c): array
    {
        return ['cost_enabled'=>$c->enabled,'cost_source_unit'=>$c->sourceUnit->value,'wholesale'=>$c->total,'unit'=>'term_total','source'=>$c->source,
            'observed_at'=>$c->observedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'fresh_until'=>$c->freshUntil->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    }
}
