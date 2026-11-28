<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\SendState;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{ExecutionWindow, OperationClaims, TransferInputs};

/** One attempt's send evidence, with its independent read and dispatch clocks. */
final readonly class AcquisitionLifecycle
{
    public const EVIDENCE = ['claim', 'admission', 'reply', 'decision', 'observation', 'acquisition_charge', 'held', 'settlement', 'answer', 'test_capacity'];
    private const PUBLICATION = ['observation' => true, 'acquisition_charge' => true, 'held' => true, 'settlement' => true, 'answer' => true];

    private function __construct(public ?AttemptFacts $facts, public AcquisitionPhase $phase, public ?string $reason = null,
        public bool $retireSeal = false, public bool $fenced = false, public bool $retired = false,
        private ?\DateTimeImmutable $decisionAt = null, public ?AcquisitionClosureKind $closure = null) {}

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
                || isset($rows['decision']) || OperationAttempts::unresolvedRead($facts)
                || (isset($rows['test_capacity']) && !self::capacityRefused($facts, $rows['test_capacity']))) {
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
            $reason = $reply['evidence']['reason'] ?? null;
            if (!is_string($reason) || $reason === '') { return new self($facts, $phase); }
            if ($reason === 'test_capacity' && !self::capacityRefused($facts, $rows['test_capacity'] ?? [])) {
                return new self($facts, $phase);
            }
            return new self($facts, AcquisitionPhase::ProvedUnsent, $reason);
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

    /** Validate the retained event for both disposition and the one TEST readmission. */
    public static function capacityRefused(AttemptFacts $facts, array $event): bool
    {
        $details = $event['evidence'] ?? [];
        if (!$facts->released || $facts->releasedAt === null || ($event['source'] ?? null) !== 'local'
            || !in_array($event['outcome'] ?? null, ['fresh', 'balance'], true)
            || ($details['phase'] ?? null) !== ($facts->sealed() ? 'purchase' : 'preflight')
            || !AcquisitionRetryProof::testCallHistory([$details]) || !isset($event['recorded_at'])) { return false; }
        $start = $facts->sealedAt ?? $facts->claimedAt;
        $refused = new \DateTimeImmutable($details['refused_at']);
        $recorded = new \DateTimeImmutable($event['recorded_at']);
        return $start !== null && $start <= $refused && $refused <= $recorded && $recorded <= $facts->releasedAt;
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

    /** Fold the purchase and its ordered attempts at the same decision time. */
    public static function purchase(Operation $op, array|false|null $history, array $rows, \DateTimeImmutable $now): self
    {
        $invalid = new self(null, AcquisitionPhase::Invalid);
        if ($history === false || $op->authority_generation < 1
            || $op->provider_order_id !== null || $op->confirmed_expires_at !== null
            || array_diff(array_keys($op->cost ?? []), ['estimated', 'currency', 'unit'])
            || array_key_exists('dispatch_attempted_at', $op->operationPolicy())) { return $invalid; }
        $terminal = $rows[0]['terminal'] ?? null;
        $latest = new self(null, AcquisitionPhase::Virgin);
        if ($history === null) {
            $raw = $op->getAttributes();
            $attempt = isset($raw['attempt']) ? OperationAttempts::decodeEvidence($raw['attempt']) : [];
            $funding = $op->receiptFunding();
            if (isset($raw['lease_expires_at']) || array_key_exists('seal', $funding)) { return $invalid; }
            $unclaimed = !isset($raw['started_at']) && !isset($raw['request_fingerprint'])
                && !array_key_exists('claim', $funding) && !array_key_exists('claim_key', $attempt);
            if (!$unclaimed || ($attempt !== [] && $attempt !== ['seal' => 'absent', 'send_state' => 'definitely_unsent'])) {
                return self::retainedClosure($op, $attempt, $funding, $unclaimed, $terminal, $invalid);
            }
        } elseif (!$history || AcquisitionAttempt::conflicts($op)
            || array_intersect_key($op->receiptFunding(), ['claim' => true, 'seal' => true])
            || self::conflictingRetainedFacts($op)) {
            return $invalid;
        }
        $origins = [];
        foreach ($history ?? [] as $index => $facts) {
            $id = $facts->token->attemptId;
            $evidence = $rows[$id] ?? [];
            $event = $evidence['claim'] ?? [];
            $claim = $event['evidence'] ?? [];
            $originId = array_key_exists('origin_attempt_id', $claim) ? $claim['origin_attempt_id'] : $id;
            if (!is_int($originId)) { return $invalid; }
            $origin = $originId === $id ? $claim : ($origins[$originId] ?? []);
            $retained = ($event['source'] ?? null) === 'retained';
            if ($facts->token->ordinal !== $index + 1 || $facts->token->operationId !== (int) $op->id
                || (!$facts->current && !$facts->released)
                || !preg_match('/^[a-f0-9]{32}$/D', $facts->token->claimKey)
                || ($claim['identity'] ?? null) !== $facts->token->claimKey
                || !is_string($origin['digest'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $origin['digest'])
                || (!$retained && (($event['source'] ?? null) !== 'local' || ($event['outcome'] ?? null) !== 'claimed'))) { return $invalid; }
            if ($originId === $id) { $origins[$id] = $origin; }
            $latest = self::reduce($facts, $evidence, $now);
            if ($latest->phase === AcquisitionPhase::ProvedUnsent && $facts->sealed()) {
                $admission = $evidence['admission'] ?? [];
                if ($facts->requestDigest !== $origin['digest'] || $facts->mode !== $op->action || !is_array($origin['funding'] ?? null)
                    || ($admission['source'] ?? null) !== 'local' || ($admission['outcome'] ?? null) !== 'sealed'
                    || ($admission['evidence']['funding'] ?? null) !== $origin['funding']) { return $invalid; }
            }
            if ($index !== array_key_last($history) && ($facts->current || !$latest->canCloseUnsent())) { return $invalid; }
        }
        if ($terminal !== null) {
            $kind = AcquisitionClosureKind::tryFrom($terminal['outcome'] ?? '');
            $bound = ['operation_key' => $op->operation_key, 'generation' => $op->authority_generation];
            $details = $terminal['evidence'] ?? [];
            if (!$kind || $op->state !== 'rejected' || ($terminal['source'] ?? null) !== 'local'
                || ($details['operation_key'] ?? null) !== $bound['operation_key'] || ($details['generation'] ?? null) !== $bound['generation']
                || !$latest->canCloseUnsent() || $latest->facts?->current
                || (array_key_exists('attempt_id', $details) && $details['attempt_id'] !== $latest->facts?->token->attemptId)
                || !self::closureDetails($op, $kind, $details, $latest)) { return $invalid; }
            return new self($latest->facts, AcquisitionPhase::Terminal, closure: $kind);
        }
        if ($latest->phase === AcquisitionPhase::Invalid) { return $latest; }
        return in_array($op->state, ['succeeded', 'rejected', 'disposed'], true) || $op->completed_at !== null
            ? new self($latest->facts, AcquisitionPhase::Terminal)
            : $latest;
    }

    private static function conflictingRetainedFacts(Operation $op): bool
    {
        $raw = $op->getAttributes()['attempt'] ?? null;
        if ($raw === null) { return false; }
        $stored = OperationAttempts::decodeEvidence($raw);
        return $stored !== [] && $stored !== ['seal' => 'absent', 'send_state' => 'definitely_unsent'];
    }

    private static function closureDetails(Operation $op, AcquisitionClosureKind $kind, array $details, self $predecessor): bool
    {
        unset($details['operation_key'], $details['generation'], $details['attempt_id']);
        if ($kind === AcquisitionClosureKind::Unsent) { return $details === []; }
        if ($predecessor->phase !== AcquisitionPhase::Virgin) { return false; }
        if ($kind === AcquisitionClosureKind::Unpaid) {
            return $details === ['invoice_id' => $op->invoice_id, 'transactions' => [], 'unfunded' => true];
        }
        $at = $op->completed_at?->toDateTimeImmutable();
        $deadline = $at ? TransferInputs::deadline($op, $at) : null;
        return $op->action === 'transfer' && $op->safe_code === 'transfer_code_timeout'
            && $op->term === 1 && $deadline !== null && $at >= $deadline && ($op->payload['epp'] ?? null) === null
            && $details === ['deadline' => $deadline->format(DATE_ATOM)];
    }

    /** Completed pre-ledger closures remain readable for late receipts. */
    private static function retainedClosure(Operation $op, array $attempt, array $funding, bool $unclaimed, ?array $terminal, self $invalid): self
    {
        if ($op->state !== 'rejected' || !AcquisitionRetryProof::settledRefusalHistory($attempt)
            || array_diff(array_keys($attempt),
                ['claim_key', 'seal', 'send_state', 'disposition', 'rejection_basis', 'terminal', 'transfer_timeout', ...AcquisitionRetryProof::LOCAL_REFUSAL_HISTORY])
            || ($attempt['seal'] ?? null) !== 'absent' || ($attempt['send_state'] ?? null) !== 'definitely_unsent') { return $invalid; }
        if (!$unclaimed && !($op->started_at !== null && is_string($op->request_fingerprint)
            && preg_match('/^[a-f0-9]{64}$/D', $op->request_fingerprint) && is_array($funding['claim'] ?? null)
            && is_string($attempt['claim_key'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $attempt['claim_key']))) { return $invalid; }
        if (array_key_exists('test_call_refusals', $attempt)
            && (!$attempt['test_call_refusals'] || ($op->completed_at !== null && array_filter($attempt['test_call_refusals'],
                static fn (array $event): bool => new \DateTimeImmutable($event['refused_at']) > $op->completed_at)))) {
            return $invalid;
        }
        $bound = ['operation_key' => $op->operation_key, 'generation' => $op->authority_generation];
        if ($terminal !== null) {
            return ($terminal['source'] ?? null) === 'local'
                && ($terminal['outcome'] ?? null) === AcquisitionClosureKind::Unsent->value
                && ($terminal['evidence'] ?? null) === $bound
                && !array_intersect_key($attempt, ['terminal' => true, 'transfer_timeout' => true, 'disposition' => true, 'rejection_basis' => true])
                ? new self(null, AcquisitionPhase::Terminal, closure: AcquisitionClosureKind::Unsent) : $invalid;
        }
        if (($attempt['disposition'] ?? null) !== 'rejected' || ($attempt['rejection_basis'] ?? null) !== 'definitely_unsent') { return $invalid; }
        $closure = match (true) {
            $unclaimed && $op->action === 'transfer' && $op->safe_code === 'transfer_code_timeout'
                && array_intersect_key($attempt['transfer_timeout'] ?? [], $bound) === $bound
                && self::closureDetails($op, AcquisitionClosureKind::TransferTimeout, $attempt['transfer_timeout'], new self(null, AcquisitionPhase::Virgin))
                => AcquisitionClosureKind::TransferTimeout,
            $unclaimed && ($attempt['terminal'] ?? null) === [...$bound, 'invoice_id' => $op->invoice_id, 'transactions' => [], 'unfunded' => true]
                => AcquisitionClosureKind::Unpaid,
            ($attempt['terminal'] ?? null) === $bound => AcquisitionClosureKind::Unsent,
            // The oldest closed registrations recorded the rejection directly on the operation.
            $op->action === 'register' && $unclaimed
                && !array_diff(array_keys($attempt), ['seal', 'send_state', 'disposition', 'rejection_basis'])
                => AcquisitionClosureKind::Unsent,
            default => null,
        };
        return $closure ? new self(null, AcquisitionPhase::Terminal, closure: $closure) : $invalid;
    }

    public function canCloseUnsent(): bool
    {
        return $this->phase === AcquisitionPhase::Virgin || $this->phase === AcquisitionPhase::ProvedUnsent && !$this->retireSeal;
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
            $purchase = OperationAttempts::purchaseProof($op, $now, lock: true);
            if (!$purchase->retireSeal || $purchase->facts?->token != $facts->token
                || $op->request_fingerprint !== $facts->requestDigest
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
