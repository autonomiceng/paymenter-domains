<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Contracts\LiveReservationPolicy;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Services\EnomLive;

final class EnomLiveReservationPolicy implements LiveReservationPolicy
{
    public function limits(AccountBinding $binding): array {return EnomLive::account($binding);}
}
