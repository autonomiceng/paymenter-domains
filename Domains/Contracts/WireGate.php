<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, RequestContext};
/** The last local check before one registrar write. */
interface WireGate
{
    /** Consumes the live grant for this exact request object and returns the send mode. A refused check throws RenewalUnsent. */
    public function consume(AdmittedOperation $op, RequestContext $context, AdmittedRegistrarDriver $writer): string;
}
