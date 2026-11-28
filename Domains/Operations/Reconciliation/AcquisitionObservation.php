<?php
// Enom 444f31c reconciliation-registration/provider-disposition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

use Paymenter\Extensions\Servers\Domains\Data\{Action, DomainSnapshot, Evidence, HistoryScan, NameserverEvidence, OrderEvidence, RequestContext, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationWriter;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, FreshTransaction, ReconciliationEvidence, WriteMoney};

final readonly class AcquisitionObservation
{
    public function __construct(public ?HistoryScan $scan = null, public ?Evidence $facts = null, public bool $covered = false,
        public ?array $proof = null, public ?NameserverEvidence $nameservers = null, public bool $transferConflict = false,
        public bool $chargeAnomaly = false, public ?AcquisitionCharges $charges = null, public ?\DateTimeImmutable $observedAt = null) {}

    public function transfer(): TransferStatus { return $this->facts?->transfer ?? TransferStatus::Unknown; }

    public static function from(AcquisitionRead $read, Evidence $facts, \DateTimeImmutable $now): self
    {
        $attempt = $read->attempt;
        $owned = $facts->domain?->owned === true;
        $transfer = $facts->transfer;
        $covered = ($attempt->query->action === Action::Transfer && $transfer !== TransferStatus::Unknown)
            || ($owned && $facts->domain->expiry !== null
                && ($facts->historyComplete || ($attempt->executionFacts && $attempt->providerReference !== null && $facts->candidates)));
        $count = count($facts->candidates);
        $charge = $count === 1 ? $facts->candidates[0]->charge : null;
        $expected = $attempt->expectedCharge;
        $chargeAnomaly = $count > 1 || ($charge && $expected
            && ($charge->currency !== $expected->currency || WriteMoney::compare($charge->amount, $expected->amount) !== 0));
        // One Enom charge can be a failed fee. It proves neither finality nor a settled refund.
        $allowed = $attempt->binding->registrarKey === 'enom' && $transfer === TransferStatus::Rejected ? 1 : 0;
        $conflict = in_array($transfer, [TransferStatus::Rejected, TransferStatus::NeedsAction], true) && ($owned || $count > $allowed);
        $proof = $read->followup && $chargeAnomaly ? null : ReconciliationEvidence::completion($attempt, $facts, $now);
        return new self($facts->historyScan, $facts, $covered, $proof, $facts->domain?->nameservers ?? $read->requestedNameservers,
            $conflict, $chargeAnomaly, AcquisitionCharges::from($attempt, $facts, $now), $now);
    }

    /** Keep facts reported before an interrupted or overdue registration read can return its result. */
    public static function collect(RequestContext $context, array &$seen): void
    {
        $context->observe(static function (DomainSnapshot|OrderEvidence $fact) use ($context, &$seen): void {
            $now = $context->now();
            if ($now >= $context->deadline) { return; }
            if ($fact instanceof DomainSnapshot) { $seen['domain'] = $fact; }
            else { $seen['orders'][] = $fact; }
            $seen['at'] ??= $now;
        });
    }

    public static function partial(AcquisitionRead $read, array $seen, ?HistoryScan $scan = null): self
    {
        if (!$seen) { return new self($scan); }
        $facts = new Evidence(domain: $seen['domain'] ?? null, candidates: $seen['orders'] ?? []);
        return new self($scan, $facts, charges: AcquisitionCharges::from($read->attempt, $facts, $seen['at']), observedAt: $seen['at']);
    }

    public function retainAfterInterruption(AcquisitionRead $read, OperationWriter $writer): void
    {
        if (!$this->facts || $read->attempt->query->action !== Action::Register) { return; }
        $id = $read->token->attempt->operationId;
        $route = $this->facts->domain ? Operation::findOrFail($id) : null;
        FreshTransaction::run(function () use ($id, $read, $writer, $route): void {
            // Proof may outlive native settlement authority. Keep the account, holding and operation lock order.
            $account = $route ? Account::where('server_id', $read->attempt->binding->serverId)->lockForUpdate()->first() : null;
            $holding = $route ? Domain::whereKey($route->domain_id)->lockForUpdate()->first() : null;
            $op = Operation::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->retainCharge($op, $read, $writer);
            if ($account && $holding) { $this->retainOwnership($op, $holding, $account, $read, $writer); }
        });
    }

    /** A matching charge can bar a negative answer even if the read cannot settle the purchase. */
    public function retainCharge(Operation $op, AcquisitionRead $read, OperationWriter $writer): void
    {
        if ($op->state === 'rejected' || !$this->charges || $this->observedAt === null) { return; }
        $writer->recordAcquisitionCharges($read->token, $this->charges->match, $this->charges->orders, $this->observedAt,
            retainAfterDeadline: $op->action === 'register');
    }

    /** Read takeover preserves timely evidence. A rejection closes the attempt to new observations. */
    public function retainOwnership(Operation $op, Domain $holding, Account $account, AcquisitionRead $read, OperationWriter $writer): void
    {
        $domain = $this->facts?->domain;
        $attempt = $read->attempt;
        if ($op->state === 'rejected' || !$this->observedAt || $this->observedAt >= $read->until || !$domain
            || !$domain->binding->matches($attempt->binding) || $domain->name !== $attempt->query->name
            || $op->domain_id !== $holding->id || $op->server_id !== $attempt->binding->serverId
            || $holding->domain !== $attempt->query->name
            || $op->operation_key !== $attempt->operationKey || $op->authority_generation !== $attempt->authorityGeneration
            || $holding->authority_generation !== $attempt->authorityGeneration
            || $op->account_fingerprint !== $attempt->binding->fingerprint
            || $holding->account_fingerprint !== $attempt->binding->fingerprint) { return; }
        try { $binding = AccountBindings::binding($account); }
        catch (\DomainException|\InvalidArgumentException|\ValueError) { return; }
        if (!$binding->matches($attempt->binding)) { return; }
        $expiry = $domain->expiry?->value;
        $expected = $attempt->expectedExpiry;
        if ($domain->owned === true || ($expiry !== null && $expected && substr($expiry, 0, 10) === substr($expected->value, 0, 10))) {
            $writer->recordObservation($read->token->attempt, ['owned' => $domain->owned, 'expiry' => $expiry], $this->observedAt);
        }
    }
}
