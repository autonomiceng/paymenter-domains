<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\DispatchReadiness;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Local current acceptance checks only; never perform provider reads under financial locks. */
interface SealPreflight extends OperationPreflight
{
    public function requireAtSeal(Operation $operation, Domain $domain, DispatchReadiness $ready): void;
}
