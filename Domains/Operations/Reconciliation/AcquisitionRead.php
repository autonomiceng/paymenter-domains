<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, NameserverEvidence};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\ReadToken;

/** Locked snapshot for a read of the original acquisition. */
final readonly class AcquisitionRead
{
    public function __construct(public AttemptEvidence $attempt, public string $stamp, public string $contract,
        public ?NameserverEvidence $requestedNameservers, public bool $watch, public bool $light, public bool $followup,
        public \DateTimeImmutable $until, public ?ReadToken $token) {}
}
