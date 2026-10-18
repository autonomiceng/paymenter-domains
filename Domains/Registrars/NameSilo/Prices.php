<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, CatalogueRate, CostUnit, Disposition, DriverResult, Query};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

final class Prices
{
    /** Verified quote -> pricing input only, never admission or a PriceSnapshot fabrication. */
    public static function rate(#[\SensitiveParameter] DriverResult $result, #[\SensitiveParameter] AccountBinding $binding,
        Query $query, string $tld, Clock $clock): CatalogueRate
    {
        return \Paymenter\Extensions\Servers\Domains\Services\CatalogueMapper::quote($result, $binding, $query, $tld, $clock->now());
    }
}
