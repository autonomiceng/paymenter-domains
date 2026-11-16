<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
/** The one native invoice an invoice-scoped money decision locked, with its items and payments. Valid only inside the
 * runner attempt that took it. Readers of other invoices or of owner-wide billing refuse it instead of reading
 * missing rows as no debt. A full context has no scope and passes every check. */
final class InvoiceScope
{
    private bool $open = true;
    public function __construct(public readonly int $invoiceId) {}
    public function close(): void { $this->open = false; }
    /** The context must hold every native row of this invoice. */
    public static function cover(array $l, int $invoiceId): void
    {
        $scope = self::live($l);
        if ($scope && $scope->invoiceId !== $invoiceId) { throw new MoneyScopeChanged('The decision needs another invoice.'); }
    }
    /** The reader needs all of the owner's invoices. */
    public static function requireFull(array $l): void
    {
        if (self::live($l)) { throw new MoneyScopeChanged('The decision needs every owner invoice.'); }
    }
    private static function live(array $l): ?self
    {
        $scope = $l['scope'] ?? null;
        if ($scope && (!$scope->open || DB::transactionLevel() < 1)) { throw new MoneyScopeChanged('The invoice scope ended with its transaction.'); }
        return $scope;
    }
}
