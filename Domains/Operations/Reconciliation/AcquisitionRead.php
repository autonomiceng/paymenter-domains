<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, NameserverEvidence};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\ReadToken;

/** Locked snapshot for a read of the original acquisition. */
final readonly class AcquisitionRead
{
    public function __construct(public AttemptEvidence $attempt, public string $stamp, public string $contract,
        public ?NameserverEvidence $requestedNameservers, public bool $watch, public bool $light, public bool $followup,
        public \DateTimeImmutable $until, public ReadToken $token) {}

    public static function stamp(Operation $op, Domain $domain): string
    {
        $facts = static fn ($model) => array_map($model->getAttribute(...), array_keys($model->getAttributes()));
        return hash('sha256', json_encode([$facts($op), $facts($domain)], JSON_THROW_ON_ERROR));
    }
}
