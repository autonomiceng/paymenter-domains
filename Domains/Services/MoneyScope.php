<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Which HoldingLocks a money decision takes. Full is today's complete holding scope. */
enum MoneyScope
{
    case Full;
}
