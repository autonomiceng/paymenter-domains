<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;

use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;

/** Local provider deployment, site and current account authority. No transport or credential-derived lineage. */
interface LiveReservationPolicy
{
    /** @return array{daily_call_cap:int,daily_spend_limit_usd:string} Throw when this binding is not admitted. */
    public function limits(AccountBinding $binding): array;
}
