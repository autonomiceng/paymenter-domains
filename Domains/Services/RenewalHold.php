<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** A local renewal refusal that names its own hold code. */
final class RenewalHold extends \DomainException
{
    public function __construct(public readonly string $reason, string $message) { parent::__construct($message); }
}
