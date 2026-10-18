<?php
// Claim/seal semantics adapted from Enom 444f31c Services/DomainOperations.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Server, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, SealPreflight, WriteGate};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AdmittedOperation, DispatchReadiness, DriverResult, Environment, Query, RequestContext};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};

/** All financial checks run twice: claim and final seal. No provider callback runs under these locks. */
final class OperationClaims
{
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock) {}

    public function claim(int $id): ?array
    {
        self::outside();
        $route = Operation::findOrFail($id);
        if ($route->state === 'running') {
            DB::transaction(function () use ($route): void {
                Domain::whereKey($route->domain_id)->lockForUpdate()->firstOrFail();
                $all = Operation::where('domain_id', $route->domain_id)->orderBy('id')->lockForUpdate()->get();
                $current = $all->firstWhere('id', $route->id);
                if ($current?->state === 'running' && (!$current->lease_expires_at || $current->lease_expires_at <= $this->clock->now())) {
                    $current->update(['state' => 'uncertain', 'safe_code' => 'stale_claim', 'lease_expires_at' => null]);
                }
            });
            return null;
        }
        if (!in_array($route->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) { return null; }
        return $this->locked($id, function ($op, $domain, $all, $funding, $server, $account): ?array {
            if ($op->state === 'running') {
                if (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now()) {
                    $op->update(['state' => 'uncertain', 'safe_code' => 'stale_claim', 'lease_expires_at' => null]);
                }
                return null; // Expiry is reconciliation work, even without a seal; never reclaim.
            }
            if (!in_array($op->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) { return null; }
            if ($op->action === 'transfer' && !TransferInputs::validCode($op->payload['epp'] ?? null)) { return null; }
            if (!self::virgin($op)) {
                $op->update(['state' => 'uncertain', 'safe_code' => 'prior_attempt', 'lease_expires_at' => null]); return null;
            }
            try { [$binding, $paid] = $this->validate($op, $domain, $all, $funding, $server, $account); }
            catch (\Throwable) { $op->update(['state' => 'held', 'safe_code' => 'claim_authority_invalid']); return null; }
            try { ContactSetup::requireReady($op); }
            catch (\DomainException) {
                // The contact service owns its transaction and rechecks payment/identity after commit.
                DB::afterCommit(static fn () => ContactRecovery::hold((int) $op->id));
                return null;
            }
            $now = $this->clock->now(); $digest = self::digest($op, $domain);
            $op->update(['state' => 'running', 'safe_code' => null, 'started_at' => $now,
                'lease_expires_at' => $now->modify('+'.ExecuteOperation::LEASE_SECONDS.' seconds'), 'request_fingerprint' => $digest,
                'attempt' => ['claim_key' => bin2hex(random_bytes(16)), 'seal' => 'absent', 'send_state' => 'definitely_unsent'],
                'funding' => [...($op->funding ?? []), 'claim' => $paid]]);
            return [$op, $domain, $binding];
        });
    }

    public function seal(Operation $claimed, DispatchReadiness $ready, ?SealPreflight $preflight = null, ?WriteGate $writer = null): ?Operation
    {
        return $this->locked((int) $claimed->id, function ($op, $domain, $all, $funding, $server, $account) use ($claimed, $ready, $preflight, $writer): ?Operation {
            if (!self::sameClaim($op, $claimed)) { return null; }
            if ($this->expire($op) || !self::unsealed($op)) { return null; }
            try {
                self::requireSameEvidence($op, $claimed); self::requireNoSendEvidence($op);
                [$binding, $paid] = $this->validate($op, $domain, $all, $funding, $server, $account);
                ContactSetup::requireReady($op);
                $preflight?->requireAtSeal($op, $domain, $ready);
                $writer?->requireAtSeal($op, $domain, $ready);
                $now = $this->clock->now();
                $ready->requireCurrent($binding, self::query($op, $domain), self::payloadDigest($op), $now);
                $lastSend = $op->started_at->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds');
                if ($ready->observedAt < $op->started_at || $op->request_fingerprint !== self::digest($op, $domain)
                    || !$op->lease_expires_at || $op->lease_expires_at < $now->modify('+'.(ExecuteOperation::REQUEST_SECONDS + ExecuteOperation::CLEANUP_SECONDS).' seconds')
                    || $lastSend < $now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds')
                    || $ready->validUntil < $now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds')) {
                    throw new \DomainException('Insufficient dispatch lease or changed request.');
                }
            } catch (\Throwable $error) {
                $op->update(['state' => 'held', 'safe_code' => $error instanceof WriteHeld ? $error->reason : 'seal_authority_invalid', 'lease_expires_at' => null]); return null;
            }
            $attempt = $op->attempt;
            $attempt['seal'] = 'sealed'; $attempt['dispatch_attempted_at'] = $now->format(DATE_ATOM);
            $attempt['send_state'] = 'possibly_sent';
            $attempt['authorized_cost'] = ['amount' => $ready->cost->amount, 'currency' => $ready->cost->currency];
            if ($ready->query->action === Action::Register) {
                $attempt['registration_admission'] = ['premium' => false, 'name' => $ready->query->name,
                    'years' => $ready->query->years, 'cost' => $attempt['authorized_cost']];
            }
            if ($ready->query->action === Action::Transfer) { $attempt['transfer_admission'] = $ready->transfer; }
            $op->update(['attempt' => $attempt, 'funding' => [...$op->funding, 'seal' => $paid]]);
            return $op;
        });
    }

    /** Post-read/write bookkeeping needs only domain -> ordered operations, never financial authority again. */
    public function finish(Operation $claimed, ?DriverResult $result = null, ?string $holdReason = null): Operation
    {
        self::outside();
        return DB::transaction(function () use ($claimed, $result, $holdReason): Operation {
            $domain = Domain::whereKey($claimed->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $claimed->domain_id)->orderBy('id')->lockForUpdate()->get();
            $op = $all->firstWhere('id', $claimed->id);
            if (!$op) { return $claimed->fresh(); }
            $sealed = !self::unsealed($op);
            $late = self::sameWorker($op, $claimed) && $sealed
                && $op->state === 'uncertain' && $op->safe_code === 'stale_claim';
            if (!self::sameClaim($op, $claimed) && !$late) { return $op; }
            $expired = $late || !$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now();
            $attempt = $op->attempt;
            if ($sealed && !array_key_exists('execution', $attempt)) {
                // Recorded null is intentional and distinct from absent pre-send execution.
                $attempt['execution'] = $result?->jsonSerialize();
                if ($result) {
                    // Evidence is the bounded neutral fact graph, never a provider response or contact payload.
                    $attempt['evidence'] = json_decode(json_encode($result->evidence(), JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
                    $attempt['charge'] = $attempt['evidence']['charge'];
                    $attempt['order_candidates'] = $attempt['evidence']['candidates'];
                }
            }
            // Ack/rejection still require action-specific correlation/completion in 21/22. No refund/release here.
            $op->attempt = $attempt;
            $completion = !$expired && $sealed && $result ? RegistrationCompletion::record($op, $domain, $result) : null;
            $op->update(array_replace(['attempt' => $attempt, 'state' => ($sealed || $expired) ? 'uncertain' : 'held',
                'safe_code' => $expired ? 'stale_claim' : ($sealed ? 'reconciliation_required' : ($holdReason ?? 'pre_send_interrupted')), 'lease_expires_at' => null], $completion ?? []));
            return $op;
        });
    }

    /** Disabled feature gates create no claim, seal or provider traffic. */
    public function holdUnclaimed(int $id, string $reason): Operation
    {
        self::outside(); $route = Operation::findOrFail($id);
        return DB::transaction(function () use ($route, $reason): Operation {
            Domain::whereKey($route->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $route->domain_id)->orderBy('id')->lockForUpdate()->get();
            $op = $all->firstWhere('id', $route->id);
            if ($op->action === 'transfer' && $op->state === 'awaiting_code' && !TransferInputs::validCode($op->payload['epp'] ?? null)) { return $op; }
            if ($op->state === 'running' && (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now())) {
                $op->update(['state' => 'uncertain', 'safe_code' => 'stale_claim', 'lease_expires_at' => null]);
            } elseif (in_array($op->state, ['queued', 'awaiting_payment', 'awaiting_code'], true) && self::virgin($op)) {
                $op->update(['state' => 'held', 'safe_code' => $reason]);
            }
            return $op;
        });
    }

    /** Authorizes nonmonetary fresh profile creation for this live, funded, unsealed claim only. */
    public function profileAuthority(Operation $claimed): array
    {
        return $this->locked((int) $claimed->id, function ($op, $domain, $all, $funding, $server, $account) use ($claimed): array {
            [$binding] = $this->validate($op, $domain, $all, $funding, $server, $account);
            self::requireSameEvidence($op, $claimed); ContactSetup::requireReady($op);
            if (!self::sameClaim($op, $claimed) || !self::unsealed($op) || $op->lease_expires_at <= $this->clock->now()
                || self::payloadDigest($op) !== self::payloadDigest($claimed)) { throw new \DomainException('Profile claim unavailable.'); }
            return [$binding, (int) $domain->user_id];
        });
    }

    /** Bridge consumption: acquire current ordered financial locks, commit, then send outside locks. */
    public function verifySend(Operation $sealed, AdmittedOperation $admitted, RequestContext $context,
        DispatchReadiness $ready, ?SealPreflight $preflight, WriteAcceptance $acceptance): array
    {
        return $this->locked((int) $sealed->id, function ($op, $domain, $all, $funding, $server, $account) use ($sealed, $admitted, $context, $ready, $preflight, $acceptance): array {
            [$binding, $paid] = $this->validate($op, $domain, $all, $funding, $server, $account);
            $binding->requireMatch($admitted->binding); $binding->requireMatch($context->binding);
            ContactSetup::requireReady($op); $preflight?->requireAtSeal($op, $domain, $ready);
            $acceptance->requireCurrent($binding, self::query($op, $domain), $op, true);
            $ready->requireCurrent($binding, self::query($op, $domain), self::payloadDigest($op), $this->clock->now());
            self::requireSameEvidence($op, $sealed); self::requireNoSendEvidence($op);
            if (!self::sameClaim($op, $sealed) || ($op->attempt['seal'] ?? null) !== 'sealed'
                || ($op->attempt['send_state'] ?? null) !== 'possibly_sent' || array_key_exists('execution', $op->attempt)
                || $op->lease_expires_at <= $this->clock->now() || $this->clock->now() >= $context->deadline
                || $op->request_fingerprint !== self::digest($op, $domain) || $op->operation_key !== $admitted->operationKey
                || $op->attempt['claim_key'] !== $admitted->claimKey || $op->authority_generation !== $admitted->authorityGeneration
                || $admitted->fundingEvidenceKey !== 'operation:'.$op->id.':seal' || !self::query($op, $domain)->matches($admitted->query)
                || self::payloadDigest($op) !== $admitted->desiredStateDigest || ($op->funding['seal'] ?? null) !== $paid
                || ($op->attempt['authorized_cost'] ?? null) !== ['amount' => $admitted->authorizedCost->amount, 'currency' => $admitted->authorizedCost->currency]) {
                throw new \DomainException('Sealed dispatch authority changed.');
            }
            return $op->payload;
        });
    }

    private static function requireSameEvidence(Operation $current, Operation $snapshot): void
    {
        foreach (['attempt', 'funding', 'cost', 'policy'] as $field) {
            if ($current->$field !== $snapshot->$field) { throw new \DomainException('Operation evidence changed.'); }
        }
    }
    /** Independent presence guard: null charge/refund/candidate facts are still uncertainty. */
    private static function requireNoSendEvidence(Operation $op): void
    {
        if (array_diff(array_keys($op->attempt ?? []), ['claim_key', 'seal', 'send_state', 'dispatch_attempted_at', 'authorized_cost', 'registration_admission', 'transfer_admission'])
            || array_key_exists('refund', $op->funding ?? []) || array_key_exists('actual', $op->cost ?? [])
            || array_key_exists('execution', $op->cost ?? []) || array_key_exists('dispatch_attempted_at', $op->policy ?? [])) {
            throw new \DomainException('Prior operation evidence blocks dispatch.');
        }
    }

    private function validate(Operation $op, Domain $domain, $all, InvoiceFunding $funding, Server $server, Account $account): array
    {
        $this->bindings->requireProcessingLocked($server, $account);
        $binding = new AccountBinding($account->registrar_key, (int) $account->server_id, Environment::from($account->environment), $account->stable_identity);
        if ($op->account_fingerprint !== $binding->fingerprint || $domain->account_fingerprint !== $binding->fingerprint
            || $op->registrar_key !== $binding->registrarKey || $op->server_id !== $binding->serverId
            || $domain->registrar_key !== $binding->registrarKey || $domain->server_id !== $binding->serverId
            || !in_array($op->action, ['register', 'transfer'], true) || $domain->status !== ($op->action === 'transfer' ? 'transfer_pending' : 'registration_pending') || !$domain->billing_managed
            || $domain->reservation_name !== $domain->domain || $domain->provider_domain_id !== null
            || $domain->provider_expires_at !== null || $domain->provider_order_id !== null || $op->provider_order_id !== null
            || $op->confirmed_expires_at !== null
            || $all->contains(static fn ($other) => $other->id !== $op->id && (!self::resolvedOther($other)
                || $other->account_fingerprint !== $op->account_fingerprint || $other->server_id !== $op->server_id
                || $other->authority_generation !== $op->authority_generation || $other->service_id !== $op->service_id))) {
            throw new \DomainException('Acquisition authority is unavailable.');
        }
        if ($op->action === 'transfer' && ($op->term !== 1 || !TransferInputs::validCode($op->payload['epp'] ?? null)
            || !TransferInputs::deadline($op, $this->clock->now()))) { throw new \DomainException('Transfer inputs incomplete.'); }
        $op->setRelation('domain', $domain); // Contact validation consumes the already locked holding.
        return [$binding, $funding->requireInitial($op, $domain)];
    }

    private static function resolvedOther(Operation $op): bool
    {
        // A state label alone is never proof of a safe earlier outcome.
        return $op->state === 'rejected' && ($op->attempt['disposition'] ?? null) === 'rejected'
            && ($op->attempt['rejection_basis'] ?? null) === 'definitely_unsent'
            && ($op->attempt['send_state'] ?? null) === 'definitely_unsent' && self::unsealed($op)
            && ($op->attempt['charge'] ?? null) === null && ($op->attempt['order_candidates'] ?? []) === [];
    }

    /** Internal ordered authority scope shared by read-only reconciliation. */
    public function locked(int $id, \Closure $callback): mixed
    {
        self::outside();
        // Discovery is before the transaction, so it cannot establish an old repeatable-read view.
        $route = Operation::findOrFail($id); $holding = Domain::findOrFail($route->domain_id);
        return DB::transaction(function () use ($route, $holding, $callback) {
            User::whereKey($holding->user_id)->lockForUpdate()->firstOrFail();
            $funding = InvoiceFunding::lock((int) $route->invoice_id);
            $server = Server::whereKey($route->server_id)->lockForUpdate()->firstOrFail();
            $account = Account::where('server_id', $route->server_id)->lockForUpdate()->firstOrFail();
            // Lock credentials before domain/operation rows (same server mutex as rotation).
            $server->settings()->orderBy('id')->lockForUpdate()->get();
            $domain = Domain::whereKey($route->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $domain->id)->orderBy('id')->lockForUpdate()->get();
            $op = $all->firstWhere('id', $route->id);
            if (!$op || $op->invoice_id !== $route->invoice_id || $op->server_id !== $route->server_id
                || $domain->user_id !== $holding->user_id || $op->domain_id !== $domain->id) {
                throw new \DomainException('Claim route changed.');
            }
            return $callback($op, $domain, $all, $funding, $server, $account);
        });
    }

    private function expire(Operation $op): bool
    {
        if ($op->lease_expires_at && $op->lease_expires_at > $this->clock->now()) { return false; }
        $op->update(['state' => 'uncertain', 'safe_code' => 'stale_claim', 'lease_expires_at' => null]);
        return true;
    }
    private static function virgin(Operation $op): bool { return ($op->attempt ?? []) === [] && !array_key_exists('refund', $op->funding ?? []) && self::unsealed($op); }
    private static function unsealed(Operation $op): bool
    {
        $a = $op->attempt ?? [];
        return (!array_key_exists('seal', $a) || $a['seal'] === 'absent')
            && !array_key_exists('dispatch_attempted_at', $a) && !array_key_exists('execution', $a)
            && !array_key_exists('provider_reference', $a) && $op->provider_order_id === null
            && !array_key_exists('execution', $op->cost ?? []) && !array_key_exists('actual', $op->cost ?? [])
            && !array_key_exists('dispatch_attempted_at', $op->policy ?? []);
    }
    private static function sameClaim(Operation $a, Operation $b): bool
    {
        return $a->state === 'running' && $b->state === 'running' && self::sameWorker($a, $b)
            && $a->getRawOriginal('lease_expires_at') === $b->getRawOriginal('lease_expires_at');
    }
    private static function sameWorker(Operation $a, Operation $b): bool
    {
        return ($a->attempt['claim_key'] ?? null) !== null && ($a->attempt['claim_key'] ?? null) === ($b->attempt['claim_key'] ?? null)
            && $a->getRawOriginal('started_at') === $b->getRawOriginal('started_at')
            && $a->request_fingerprint === $b->request_fingerprint;
    }
    public static function payloadDigest(Operation $op): string { return hash('sha256', json_encode($op->payload, JSON_THROW_ON_ERROR)); }
    public static function query(Operation $op, Domain $domain): Query { return new Query($domain->domain, Action::from($op->action), (int) $op->term); }
    private static function digest(Operation $op, Domain $domain): string
    {
        return hash('sha256', json_encode([$op->operation_key, $domain->domain, $op->action, $op->term, $op->account_fingerprint,
            $op->authority_generation, self::payloadDigest($op)], JSON_THROW_ON_ERROR));
    }
    public static function outside(): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Operation work must start outside database transactions.'); }
    }
}
