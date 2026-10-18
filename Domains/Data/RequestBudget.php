<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

final class RequestBudget
{
    private int $used = 0;
    public function __construct(public readonly int $limit)
    {
        if ($limit < 0 || $limit > 200) { throw new \InvalidArgumentException('Invalid request budget.'); }
    }
    public function remaining(): int { return $this->limit - $this->used; }
    public function consume(): void
    {
        if ($this->remaining() < 1) { throw new \RuntimeException('Registrar request budget exhausted.'); }
        $this->used++;
    }
}
