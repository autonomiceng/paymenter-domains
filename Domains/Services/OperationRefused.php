<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Typed source guard; retains DomainException compatibility outside staff review. */
final class OperationRefused extends \DomainException
{
    public function __construct(public readonly OperationFailure $reason)
    { parent::__construct($reason->message()); }
}
