<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\SendState;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{ExecutionWindow, OperationClaims};

/** One attempt's send evidence, with its independent read and dispatch clocks. */
final readonly class AcquisitionLifecycle
{
    public const EVIDENCE = ['claim', 'admission', 'reply', 'decision', 'observation', 'acquisition_charge', 'held', 'settlement', 'answer'];
    private const PUBLICATION = ['observation' => true, 'acquisition_charge' => true, 'held' => true, 'settlement' => true, 'answer' => true];

    private function __construct(public ?AttemptFacts $facts, public AcquisitionPhase $phase, public ?string $reason = null,
        public bool $retireSeal = false, public bool $fenced = false, public bool $retired = false,
        private ?\DateTimeImmutable $decisionAt = null) {}

    /** Read-only reduction also accepts batch-loaded evidence. A read marker is not proof of a send. */
    public static function reduce(AttemptFacts $facts, array $rows, \DateTimeImmutable $now, bool $contact = false): self
    {
        $phase = AcquisitionPhase::Invalid;
        $badTimestamp = $facts->claimedAt === null || $facts->released && ($facts->current || $facts->releasedAt < $facts->claimedAt)
            || $facts->sealed() && $facts->sealedAt < $facts->claimedAt;
        if ($badTimestamp || in_array(OperationAttempts::UNREADABLE, $rows, true)) { return new self($facts, $phase); }
        if (isset($rows['settlement']) || in_array($rows['decision']['outcome'] ?? null, ['succeeded', 'rejected'], true)) {
            return new self($facts, AcquisitionPhase::Terminal);
        }
        $positive = array_intersect_key($rows, self::PUBLICATION);
        $reply = $rows['reply'] ?? null;
        if (!$facts->sealed()) {
            if ($facts->sendState !== SendState::DefinitelyUnsent || $facts->grantConsumedAt !== null
                || $positive || isset($rows['admission']) || $reply
                || isset($rows['decision']) || OperationAttempts::unresolvedRead($facts)) {
                return new self($facts, $phase);
            }
            if ($facts->current && !$facts->released && OperationAttempts::recordedLease($facts)) {
                return new self($facts, $facts->claimUntil > $now ? AcquisitionPhase::LiveAuthority : AcquisitionPhase::ProvedUnsent);
            }
            return new self($facts, $facts->released ? AcquisitionPhase::ProvedUnsent : $phase);
        }
        if ($facts->sendState === SendState::DefinitelyUnsent) {
            if ($positive || isset($rows['decision']) || ($reply['source'] ?? null) !== 'local_refusal' || ($reply['outcome'] ?? null) !== 'unsent'
                || OperationAttempts::unresolvedRead($facts)) {
                return new self($facts, $phase);
            }
            return new self($facts, AcquisitionPhase::ProvedUnsent, $reply['evidence']['reason'] ?? null);
        }
        if (!$facts->current || $facts->released) { return new self($facts, $phase); }
        if ($contact && ($reply['source'] ?? null) === 'reply' && ($reply['outcome'] ?? null) === 'created'
            && is_string($reply['evidence']['provider_id'] ?? null) && preg_match('/^[1-9][0-9]{0,19}$/D', $reply['evidence']['provider_id'])) {
            return new self($facts, AcquisitionPhase::ContactIdSaved);
        }
        if ($facts->sendState === SendState::PossiblySent && $facts->grantConsumedAt === null && !$reply && !$positive
            && !isset($rows['decision']) && ($rows['claim']['source'] ?? null) === 'local'
            && ($rows['claim']['evidence']['identity'] ?? null) === $facts->token->claimKey
            && ($rows['admission']['source'] ?? null) === 'local' && ($rows['admission']['outcome'] ?? null) === 'sealed') {
            if (!OperationAttempts::recordedLease($facts) || $facts->dispatchUntil === null
                || $facts->readToken !== null && $facts->readUntil === null) { return new self($facts, $phase); }
            if ($facts->claimUntil > $now || $facts->dispatchUntil > $now || $facts->reading($now)) {
                return new self($facts, AcquisitionPhase::LiveAuthority);
            }
            return new self($facts, AcquisitionPhase::ProvedUnsent, 'expired_unused_grant', retireSeal: true);
        }
        return new self($facts, AcquisitionPhase::PossibleHandoff);
    }

    public static function read(AttemptFacts $facts, \DateTimeImmutable $now, bool $lock = false, bool $includeClosure = false): self
    {
        $token = $facts->token;
        $rows = OperationAttempts::evidenceMany([$token->operationId], $includeClosure ? [...self::EVIDENCE, 'terminal'] : self::EVIDENCE,
            $lock)[$token->operationId] ?? [];
        $state = isset($rows[0]['terminal'])
            ? new self($facts, $rows[0]['terminal'] === OperationAttempts::UNREADABLE ? AcquisitionPhase::Invalid : AcquisitionPhase::Terminal)
            : self::reduce($facts, $rows[$token->attemptId] ?? [], $now);
        return $lock && DB::transactionLevel() > 0
            ? new self($facts, $state->phase, $state->reason, $state->retireSeal, decisionAt: $now)
            : $state;
    }

    /** Every retained attempt must independently prove no handoff. An active or expired seal needs a locked retirement first. */
    public static function unsentHistory(array $history, array $rows, \DateTimeImmutable $now): bool
    {
        foreach ($history as $facts) {
            $attempt = self::reduce($facts, $rows[$facts->token->attemptId] ?? [], $now);
            if ($attempt->phase !== AcquisitionPhase::ProvedUnsent || $attempt->retireSeal
                || $facts->current && !$facts->sealed()) { return false; }
        }
        return true;
    }

    /** End expired purchase authority under the existing holding and operation locks. */
    public static function settle(Operation $op, Domain $domain, \DateTimeImmutable $now): self
    {
        if (DB::transactionLevel() === 0) { throw new \LogicException('Acquisition settlement requires the locked transaction.'); }
        $key = AcquisitionAttempt::key($op->action);
        if (!$key || $domain->adoptionPending() || $op->domain_id !== $domain->id || $op->service_id !== $domain->service_id
            || $op->server_id !== $domain->server_id || $op->registrar_key !== $domain->registrar_key
            || $op->account_fingerprint !== $domain->account_fingerprint || $op->authority_generation !== $domain->authority_generation
            || $op->authority_generation < 1) { return new self(null, AcquisitionPhase::Invalid); }
        if ($op->completed_at !== null || in_array($op->state, ['succeeded', 'rejected', 'disposed', 'cancelled'], true)) {
            return new self(null, AcquisitionPhase::Terminal);
        }
        if ($op->acquisitionStepAbsent()) { return new self(null, AcquisitionPhase::Invalid); }
        $facts = OperationAttempts::latest((int) $op->id, $key, true);
        if (!$facts) { return new self(null, AcquisitionPhase::Invalid); }
        $state = self::read($facts, $now, true);
        $writer = OperationWriter::at($now);
        if ($state->retireSeal) {
            $history = OperationAttempts::purchaseHistory((int) $op->id, $key, true);
            $rows = OperationAttempts::evidenceMany([(int) $op->id], self::EVIDENCE, true)[(int) $op->id] ?? [];
            if (!is_array($history) || !$history || array_pop($history)->token != $facts->token
                || !self::unsentHistory($history, $rows, $now)
                || $op->provider_order_id !== null || $op->confirmed_expires_at !== null
                || $op->request_fingerprint !== $facts->requestDigest || array_intersect_key($op->cost ?? [], ['actual' => true, 'execution' => true])
                || array_key_exists('dispatch_attempted_at', $op->operationPolicy()) || AcquisitionAttempt::conflicts($op)
                || $domain->provider_domain_id !== null || $domain->provider_order_id !== null || $domain->provider_expires_at !== null
                || $domain->status !== ($op->action === 'transfer' ? 'transfer_pending' : 'registration_pending')
                || $writer->retireUnusedPurchase($state) !== Transition::Applied) {
                return new self($facts, AcquisitionPhase::Invalid);
            }
            $op->setRelation('domain', $domain);
            $op->reloadAcquisitionAttempt();
            $op->update(['state' => 'held', 'lease_expires_at' => null, 'safe_code' => ExecutionWindow::pastTransferCutoff($op, $now)
                ? 'transfer_setup_review_required' : 'acquisition_definitely_unsent']);
            OperationClaims::recordHold($op, $now);
            return new self($op->acquisitionAttemptFacts(), AcquisitionPhase::ProvedUnsent, $state->reason, retired: true);
        }
        if ($facts->current && $facts->readToken !== null && $facts->readUntil !== null && $facts->readUntil <= $now
            && $writer->fencePurchaseRead($facts, preserveTimelyCharge: $op->action === 'register')) {
            return new self(OperationAttempts::attempt($facts->token, true), $state->phase, $state->reason, fenced: true, decisionAt: $now);
        }
        return $state;
    }

    public function permitsRead(): bool
    {
        return $this->phase === AcquisitionPhase::PossibleHandoff && $this->facts?->current && !$this->facts->released;
    }

    public function readBusy(\DateTimeImmutable $now): bool
    { return $this->facts?->reading($now) ?? false; }

    /** A fenced registration read can still own retained evidence until a successor opens. */
    public function ownsReadIdentity(ReadToken $read): bool
    {
        $facts = $this->facts;
        return $this->permitsRead() && $facts->token == $read->attempt && $facts->readToken !== null
            && hash_equals($facts->readToken, $read->token) && $facts->readUntil == $read->until;
    }

    public function authorizesPublication(ReadToken $read, \DateTimeImmutable $now): bool
    { return $this->decisionAt === $now && $this->ownsReadIdentity($read) && $now < $read->until; }
}
