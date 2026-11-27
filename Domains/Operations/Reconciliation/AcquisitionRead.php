<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AttemptEvidence, NameserverEvidence};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\ReadToken;
use Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence;

/** Locked snapshot for a read of the original acquisition. */
final readonly class AcquisitionRead
{
    public function __construct(public AttemptEvidence $attempt, public string $stamp, public string $contract,
        public ?NameserverEvidence $requestedNameservers, public bool $watch, public bool $light, public bool $followup,
        public \DateTimeImmutable $until, public ReadToken $token) {}

    /** An invalid legacy envelope cannot be adopted for a read. */
    public static function originalEvidence(Operation $op, Domain $domain, AccountBinding $binding): ?AttemptEvidence
    {
        try { return ReconciliationEvidence::attempt($op, $domain, $binding); }
        catch (\DomainException|\InvalidArgumentException|\DateException $error) {
            if ($op->acquisitionAttemptFacts()?->sealed()) { throw $error; }
            return null;
        }
    }

    public static function requestedNameservers(Operation $op): ?NameserverEvidence
    {
        if ($op->action !== 'transfer' || $op->registrar_key !== 'namesilo' || !($op->payload['nameservers'] ?? [])) {
            return null;
        }
        try {
            return NameserverEvidence::requested($op->payload['nameservers'], 'transfer_request',
                new \DateTimeImmutable($op->attempt['dispatch_attempted_at']));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function stamp(Operation $op, Domain $domain): string
    {
        $facts = static fn ($model) => array_map($model->getAttribute(...), array_keys($model->getAttributes()));
        return hash('sha256', json_encode([$facts($op), $facts($domain)], JSON_THROW_ON_ERROR));
    }
}
