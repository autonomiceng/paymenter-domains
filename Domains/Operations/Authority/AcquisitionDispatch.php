<?php
// Claim and seal semantics adapted from Enom 444f31c Services/DomainOperations.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, SealPreflight, WriteGate};
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DispatchReadiness, RequestContext};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{ContactProfileHold, ContactSetup, ProfileAuthorityMismatch,
    ReadDeferred, RuntimeIneligible, RuntimeReason, TransferInputs, WriteAcceptance, WriteHeld};

/** Final preflight and just-in-time authority for a reserved acquisition exchange. */
final readonly class AcquisitionDispatch
{
    public function __construct(private AcquisitionAuthority $authority, private OperationWriter $attempts, private Clock $clock) {}
    public function seal(Operation $claimed, DispatchReadiness $ready, \DateTimeImmutable $entry, ?SealPreflight $preflight = null, ?WriteGate $writer = null, ?RequestContext $wire = null): ?Operation
    {
        return $this->authority->locked((int) $claimed->id, function ($op, $domain, $all, $funding, $server, $account) use ($claimed, $ready, $entry, $preflight, $writer, $wire): ?Operation {
            if (!AcquisitionAttempt::sameClaim($op, $claimed)) { return null; }
            if ($this->authority->expire($op) || !AcquisitionRetryProof::unsealed($op)) { return null; }
            $op->setRelation('domain', $domain);
            $savedContact = ContactProfileHold::savedForRecovery($op, true) !== null;
            try {
                self::requireSameEvidence($op, $claimed); self::requireNoSendEvidence($op);
                [$binding, $paid] = $this->authority->validate($op, $domain, $all, $funding, $server, $account);
                if ($op->registrar_key === 'namesilo' && $op->contactRecheckSince() !== null && !$savedContact) {
                    throw new WriteHeld(ContactProfileHold::CODE);
                }
                if ($savedContact && ($op->funding['claim'] ?? null) !== $paid) {
                    throw new \DomainException('Contact funding changed.');
                }
                $cutoff = null;
                if ($op->action === 'transfer') {
                    $now = $this->clock->now();
                    $cutoff = TransferInputs::deadline($op, $now);
                    if ($cutoff === null || $cutoff <= $now) { throw new WriteHeld('transfer_setup_review_required'); }
                }
                ContactSetup::requireReady($op);
                $preflight?->requireAtSeal($op, $domain, $ready);
                $writer?->requireAtSeal($op, $domain, $ready);
                $wire?->requireReservedCapacity();$now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
                $ready->requireCurrent($binding, AcquisitionAuthority::query($op, $domain), AcquisitionAuthority::payloadDigest($op), $now);
                $window = AcquisitionResult::window($op, true) ?? throw new \DomainException('Missing acquisition window.');
                if (!$window->fits($now)) { throw new WriteHeld($window->blockedReason($now)); }
                $lastSend = min($window->end,
                    $entry->setTimezone(new \DateTimeZone('UTC'))->modify('+'.(ExecuteOperation::EXECUTION_SECONDS - ExecuteOperation::FINISH_SECONDS).' seconds'));
                if ($ready->observedAt < $window->start || $op->request_fingerprint !== AcquisitionAuthority::digest($op, $domain)
                    || !$op->lease_expires_at || $op->lease_expires_at < $now->modify('+'.(ExecuteOperation::REQUEST_SECONDS + ExecuteOperation::CLEANUP_SECONDS).' seconds')
                    || $lastSend < $now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds')
                    || $ready->validUntil < $now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds')) {
                    throw new \DomainException('Insufficient dispatch lease or changed request.');
                }
            } catch (ReadDeferred $error) {throw $error;} catch (\Throwable $error) {
                $reason=$error instanceof WriteHeld?$error->reason:
                    ($error instanceof RuntimeIneligible && $error->reason === RuntimeReason::ProcessingOff
                        ? 'processing' : 'seal_authority_invalid');
                $this->releasePreSeal($claimed);
                $op->reloadAcquisitionAttempt();
                $op->update(['state' => 'held', 'safe_code' => $reason, 'lease_expires_at' => null]); return null;
            }
            $until = min($lastSend, $op->lease_expires_at, $ready->validUntil, $wire?->deadline ?? $lastSend, $cutoff ?? $lastSend, $window->effectiveEnd());
            $this->persistSeal($op, $claimed, $ready, $until, $paid, $window);
            // A retry's backoff paces only this send. Once it may be sent, status checks keep their own schedule.
            if (array_key_exists('balance_retry', $op->policy ?? [])) {
                DB::table('domains_runtime_operations')->where('operation_id', $op->id)->where('due_at', '>', $now->getTimestamp())->update(['due_at' => $now->getTimestamp()]);
            }
            return $op;
        });
    }

    private function persistSeal(Operation $op, Operation $claimed, DispatchReadiness $ready, \DateTimeImmutable $until,
        array $paid, AcquisitionWindow $window): void
    {
        $cost = ['amount' => $ready->cost->amount, 'currency' => $ready->cost->currency];
        $admission = $ready->query->action === Action::Register
            ? ['registration_admission' => ['premium' => false, 'name' => $ready->query->name, 'years' => $ready->query->years, 'cost' => $cost]]
            : ['transfer_admission' => $ready->transfer];
        $token = $claimed->acquisitionWorker;
        $revision = $op->transition_revision + 1;
        if ($this->attempts->seal($token, new SealTerms($revision, $op->request_fingerprint, $ready->cost, $op->action, $until),
            ['funding' => $paid, 'window' => $window->seal($op->action === 'transfer'), ...$admission]) !== Transition::Applied) {
            throw new \DomainException('Acquisition claim changed.');
        }
        $op->acquisitionWorker = $token;
        $op->reloadAcquisitionAttempt();
        $op->update(['transition_revision' => $revision]);
    }

    private function releasePreSeal(Operation $claimed): void
    {
        $this->attempts->releasePreSeal($claimed->acquisitionWorker);
    }

    /** Authorizes nonmonetary fresh profile creation for this live, funded, unsealed claim only. */
    public function profileAuthority(Operation $claimed): array
    { return $this->withProfileAuthority($claimed,static fn($binding,$owner)=>[$binding,$owner]); }

    /** Ledger locks follow the same funded parent locks; the callback must perform no I/O. */
    public function withProfileAuthority(Operation $claimed,\Closure $work): mixed
    {
        return $this->authority->locked((int) $claimed->id, function ($op, $domain, $all, $funding, $server, $account) use ($claimed,$work): mixed {
            [$binding, $paid] = $this->authority->validate($op, $domain, $all, $funding, $server, $account);
            if (!self::sameEvidence($op, $claimed) || ($op->funding['claim'] ?? null) !== $paid
                || $op->authority_generation !== $claimed->authority_generation || $op->authority_generation !== $domain->authority_generation
                || ($claimed->relationLoaded('domain') && $claimed->domain->user_id !== $domain->user_id)) {
                throw new ProfileAuthorityMismatch('Operation evidence changed.');
            }
            ContactSetup::requireReady($op);
            if (!AcquisitionAttempt::sameClaim($op, $claimed) || !AcquisitionRetryProof::unsealed($op) || $op->lease_expires_at <= $this->clock->now()
                || $op->request_fingerprint !== AcquisitionAuthority::digest($op, $domain)
                || AcquisitionAuthority::payloadDigest($op) !== AcquisitionAuthority::payloadDigest($claimed)) {
                throw new ProfileAuthorityMismatch('Profile claim unavailable.');
            }
            return $work($binding, (int) $domain->user_id, $op);
        });
    }

    /** Bridge consumption: acquire current ordered financial locks, commit, then send outside locks. */
    public function verifySend(Operation $sealed, AdmittedOperation $admitted, RequestContext $context,
        DispatchReadiness $ready, ?SealPreflight $preflight, WriteAcceptance $acceptance): array
    {
        return $this->authority->locked((int) $sealed->id, function ($op, $domain, $all, $funding, $server, $account) use ($sealed, $admitted, $context, $ready, $preflight, $acceptance): array {
            [$binding, $paid] = $this->authority->validate($op, $domain, $all, $funding, $server, $account);
            $binding->requireMatch($admitted->binding); $binding->requireMatch($context->binding);
            ContactSetup::requireReady($op); $preflight?->requireAtSeal($op, $domain, $ready);
            $acceptance->requireCurrent($binding, AcquisitionAuthority::query($op, $domain), $op, true);
            $ready->requireCurrent($binding, AcquisitionAuthority::query($op, $domain), AcquisitionAuthority::payloadDigest($op), $this->clock->now());
            self::requireSameEvidence($op, $sealed); self::requireNoSendEvidence($op);
            if (!AcquisitionAttempt::sameClaim($op, $sealed) || ($op->attempt['seal'] ?? null) !== 'sealed'
                || ($op->attempt['send_state'] ?? null) !== 'possibly_sent' || array_key_exists('execution', $op->attempt)
                || $op->lease_expires_at <= $this->clock->now() || $this->clock->now() >= $context->deadline
                || $op->request_fingerprint !== AcquisitionAuthority::digest($op, $domain) || $op->operation_key !== $admitted->operationKey
                || $op->attempt['claim_key'] !== $admitted->claimKey || $op->authority_generation !== $admitted->authorityGeneration
                || $admitted->fundingEvidenceKey !== 'operation:'.$op->id.':seal' || !AcquisitionAuthority::query($op, $domain)->matches($admitted->query)
                || AcquisitionAuthority::payloadDigest($op) !== $admitted->desiredStateDigest || ($op->funding['seal'] ?? null) !== $paid
                || ($op->attempt['authorized_cost'] ?? null) !== ['amount' => $admitted->authorizedCost->amount, 'currency' => $admitted->authorizedCost->currency]) {
                throw new \DomainException('Sealed dispatch authority changed.');
            }
            $token = $sealed->acquisitionWorker;
            $proof = $token ? OperationAttempts::attempt($token, true) : null;
            if (!$proof || $proof->operationRevision !== $op->transition_revision || $proof->requestDigest !== $op->request_fingerprint
                || $this->attempts->consumeGrant($token) !== Transition::Applied) {
                throw new \DomainException('Acquisition grant changed.');
            }
            return $op->payload;
        });
    }

    private static function requireSameEvidence(Operation $current, Operation $snapshot): void
    {
        if (!self::sameEvidence($current, $snapshot)) { throw new \DomainException('Operation evidence changed.'); }
    }
    private static function sameEvidence(Operation $current, Operation $snapshot): bool
    {
        foreach (['attempt', 'funding', 'cost', 'policy'] as $field) {
            if ($current->$field !== $snapshot->$field) { return false; }
        }
        return true;
    }
    /** Independent presence guard: null charge/refund/candidate facts are still uncertainty. */
    public static function requireNoSendEvidence(Operation $op): void
    {
        $attempt = $op->attempt ?? [];
        if (!AcquisitionRetryProof::settledRefusalHistory($attempt)
            || array_diff(array_keys($attempt), ['claim_key', 'seal', 'send_state', ...AcquisitionRetryProof::LOCAL_REFUSAL_HISTORY, 'dispatch_attempted_at', 'authorized_cost', 'registration_admission', 'transfer_admission'])
            || array_key_exists('refund', $op->funding ?? []) || array_key_exists('actual', $op->cost ?? [])
            || array_key_exists('execution', $op->cost ?? []) || array_key_exists('dispatch_attempted_at', $op->policy ?? [])) {
            throw new \DomainException('Prior operation evidence blocks dispatch.');
        }
    }
}
