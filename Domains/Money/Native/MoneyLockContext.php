<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Native;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, MoneyRequest, MoneyScopeChanged};

/** The holding rows acquired for one decision, valid only inside that decision's transaction. */
final class MoneyLockContext
{
    private bool $open = true;
    private array $facts = [];

    private function __construct(private readonly Connection $connection, private readonly ?int $invoiceId) {}

    public function __clone(): void { throw new \LogicException('Money locks cannot be cloned.'); }
    public function __serialize(): array { throw new \LogicException('Money locks cannot be persisted.'); }

    /** The context ends before commit invokes any native billing callbacks. */
    public static function decide(MoneyRequest $request, ?int $invoiceId, \Closure $decide): mixed
    {
        if ($invoiceId !== null && $invoiceId !== $request->invoiceId) { throw new \LogicException('Money decision scope changed.'); }
        $context = new self(DB::connection(), $invoiceId);
        try {
            $context->facts = HoldingLocks::take($request->seed, $request->staffId, released: $request->released, money: $context);
            return $decide($context);
        } finally {
            $context->open = false;
        }
    }

    public function invoiceId(): ?int
    {
        if (!$this->active()) { throw new MoneyScopeChanged('The money locks ended with their decision.'); }
        return $this->invoiceId;
    }

    public function domain(): Domain { return $this->facts()['domain']; }

    public function operations(): Collection { return $this->facts()['operations']; }

    /** Adapter for the shared holding policies that also accept advisory reads. */
    public function facts(): array
    {
        $this->invoiceId();
        return $this->facts + ['money' => $this];
    }

    public static function ownsFullHolding(array $facts): bool
    {
        $context = $facts['money'] ?? null;
        return $context instanceof self && $context->active() && $context->invoiceId === null
            && ($facts['domain'] ?? null) === $context->facts['domain']
            && ($facts['service'] ?? null) === $context->facts['service']
            && ($facts['operations'] ?? null) === $context->facts['operations'];
    }

    private function active(): bool
    {
        return $this->open && DB::connection() === $this->connection && $this->connection->transactionLevel() === 1
            && $this->connection->getPdo()->inTransaction();
    }
}
