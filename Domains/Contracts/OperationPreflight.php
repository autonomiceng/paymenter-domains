<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;

use Paymenter\Extensions\Servers\Domains\Data\{DispatchReadiness, RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};

/** Trusted action policy: contacts/registry, fresh ordinary-name eligibility and exact cost/capability.
 * No production implementation is registered. Reads must finish outside database transactions.
 */
interface OperationPreflight
{
    public function inspect(Operation $operation, Domain $domain, RequestContext $context): DispatchReadiness;
}
