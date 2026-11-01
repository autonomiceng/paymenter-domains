<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence,ReconciliationResult,RequestContext};
/** One status read for ordinary pending. Terminal/odd status continues full reconciliation in the same budget. */
interface TransferPoller
{
    public function pollTransfer(AttemptEvidence $attempt,RequestContext $context): ReconciliationResult;
}
