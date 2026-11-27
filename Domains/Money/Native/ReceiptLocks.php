<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Native;

use App\Models\Invoice;
use Illuminate\Support\Collection;

final readonly class ReceiptLocks
{
    public function __construct(public Invoice $invoice, public Collection $items, public CheckoutLocks $checkout) {}
}
