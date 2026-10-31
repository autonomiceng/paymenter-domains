<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** A bounded refresh stopped; completed line receipts remain usable on retry. */
final class CheckoutRefreshTimeout extends \DomainException {}
