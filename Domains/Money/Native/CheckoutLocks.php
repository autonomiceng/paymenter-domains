<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Native;

use Illuminate\Support\Collection;

/** Native checkout rows of the services referenced by the locked invoice items. */
final readonly class CheckoutLocks
{
    public function __construct(
        public Collection $orders,
        public Collection $services,
        public Collection $products,
        public Collection $plans,
        public Collection $cancellations,
    ) {}
}
