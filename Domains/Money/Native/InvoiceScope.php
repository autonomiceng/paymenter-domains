<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Native;

use Paymenter\Extensions\Servers\Domains\Services\MoneyScopeChanged;

/** Invoice-scoped decisions retry on the full owner scope when a reader requires more rows. */
final class InvoiceScope
{
    public static function cover(array $facts, int $invoiceId): void
    {
        $held = ($facts['money'] ?? null)?->invoiceId();
        if ($held !== null && $held !== $invoiceId) { throw new MoneyScopeChanged('The decision needs another invoice.'); }
    }

    public static function requireFull(array $facts): void
    {
        if (($facts['money'] ?? null)?->invoiceId() !== null) { throw new MoneyScopeChanged('The decision needs every owner invoice.'); }
    }
}
