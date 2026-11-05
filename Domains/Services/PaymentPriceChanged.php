<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** The current cost no longer fits the issued invoice; the invoice stays as issued and is not charged. */
final class PaymentPriceChanged extends \DomainException
{
    public function __construct(public readonly string $name) { parent::__construct('Order cost exceeds the issued invoice.'); }
}
