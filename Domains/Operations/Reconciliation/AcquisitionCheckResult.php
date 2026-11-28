<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

use Paymenter\Extensions\Servers\Domains\Models\Operation;

/** The outcome of this invocation, including local outcomes with no registrar result. */
final readonly class AcquisitionCheckResult
{
    public function __construct(public Operation $operation, public array $check) {}

    public static function unavailable(Operation $operation, string $reason): self
    { return new self($operation, ['outcome' => 'unavailable', 'reason' => $reason]); }
}
