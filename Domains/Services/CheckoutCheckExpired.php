<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Missing/expired proof can be renewed outside checkout locks without changing the offer. */
final class CheckoutCheckExpired extends \DomainException
{
    public function __construct() { parent::__construct('The domain check expired. Your cart is saved. Try checkout again.'); }
}
