<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Exact decimal evidence only; arithmetic and pricing rules belong to the pricing slice. */
final readonly class Money
{
    public function __construct(public string $amount, public string $currency)
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,17})\.[0-9]{1,6}$/D', $amount)
            || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException('Invalid exact monetary evidence.');
        }
    }
}
