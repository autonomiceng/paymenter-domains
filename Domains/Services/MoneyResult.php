<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
final readonly class MoneyResult
{
    public function __construct(public MoneyOutcome $outcome, public mixed $value = null, public ?\Throwable $error = null) {}
}
