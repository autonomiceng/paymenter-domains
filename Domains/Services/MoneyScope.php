<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Which HoldingLocks a money decision takes. Full is today's complete holding scope. Invoice keeps the owner, Credit,
 * domain and history locks in the same order but locks only the request's invoice, its items and its payments. */
enum MoneyScope
{
    case Full;
    case Invoice;
}
