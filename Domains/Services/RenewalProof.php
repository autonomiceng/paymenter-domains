<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AttemptEvidence, DomainSnapshot, OrderEvidence};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};

/** An attempt's registrar read claim and any positive evidence retained from its replies. */
final class RenewalProof
{
    private const CLAIM_GRACE_SECONDS = 300;
    private bool $saved = false;
    private bool $pending = false;
    private bool $superseded = false;

    private function __construct(private readonly int $operationId, private readonly string $identity, private readonly string $token,
        private readonly AttemptEvidence $attempt, private readonly \DateTimeImmutable $deadline, private readonly Clock $clock) {}

    private static function claim(Operation $op): ?array
    {
        $claim = $op->policy['renewal_check'] ?? null;
        return self::sameIdentity($claim, 'identity', RegistrarAnswers::identity($op)) ? $claim : null;
    }

    public static function claimed(Operation $op): bool { return self::claim($op) !== null; }

    public static function running(Operation $op, \DateTimeImmutable $now): bool
    { return (int) (self::claim($op)['until'] ?? 0) > $now->getTimestamp(); }

    /** Call under the holding locks after admission. An expired claim may belong to a worker that saw unsaved proof. */
    public static function open(Operation $op, AttemptEvidence $attempt, \DateTimeImmutable $deadline, Clock $clock): self
    {
        $read = new self((int) $op->id, RegistrarAnswers::identity($op), bin2hex(random_bytes(16)), $attempt, $deadline, $clock);
        $op->policy = [...$op->policy, 'renewal_check' => ['identity' => $read->identity, 'token' => $read->token,
            'until' => $deadline->getTimestamp() + self::CLAIM_GRACE_SECONDS, 'unresolved' => self::claimed($op)]];
        if (!$op->save()) { throw new \RuntimeException('Renewal read claim could not be saved.'); }
        return $read;
    }

    private function matches(Operation $op): bool
    {
        return (int) $op->id === $this->operationId && (int) $op->server_id === $this->attempt->binding->serverId
            && $op->account_fingerprint === $this->attempt->binding->fingerprint
            && hash_equals($this->identity, RegistrarAnswers::identity($op));
    }

    private function ownedClaim(Operation $op): ?array
    {
        $claim = $op->policy['renewal_check'] ?? null;
        return $this->matches($op) && self::sameIdentity($claim, 'identity', $this->identity)
            && ($claim['token'] ?? null) === $this->token ? $claim : null;
    }

    /** Recheck the registrar read deadline under the final holding locks. */
    public function allowsAnswer(Operation $op, AccountBinding $binding): bool
    {
        $claim = $this->ownedClaim($op);
        $now = $this->clock->now();
        return $claim !== null && ($claim['unresolved'] ?? false) === false && $this->attempt->binding->matches($binding)
            && $now < $this->deadline && (int) ($claim['until'] ?? 0) > $now->getTimestamp();
    }

    /** A stale finisher cannot clear a successor's claim. Keep uncertainty inherited from an earlier read. */
    public function releaseLocked(Operation $op): bool
    {
        $claim = $this->ownedClaim($op);
        if ($claim === null) { return false; }
        $policy = $op->policy;
        unset($policy['renewal_check']);
        if (($claim['unresolved'] ?? false) === true) {
            $policy['renewal_check'] = ['identity' => $this->identity, 'unresolved' => true];
        }
        self::savePolicy($op, $policy);
        return true;
    }

    /** Release safe reads independently of owner locks, projection, scheduling and the staff audit. */
    public function release(): bool
    { return $this->withLockedOperation(fn (Operation $op) => $this->releaseLocked($op)); }

    /** Observe each reply before a later exchange can fail or run out of time. */
    public function saw(DomainSnapshot|OrderEvidence $seen): void
    {
        if ($this->saved || $this->superseded || !$seen->binding->matches($this->attempt->binding)) { return; }
        $name = $this->attempt->query->name;
        $before = $this->attempt->beforeExpiry?->value;
        $positive = $seen instanceof OrderEvidence ? $seen->query->name === $name && $seen->charge !== null
            : $seen->name === $name && $seen->owned === true && $before !== null && $seen->expiry !== null
                && substr($seen->expiry->value, 0, 10) > substr($before, 0, 10);
        if ($positive) { $this->pending = true; $this->write(); }
    }

    /** Retry a failed proof write before settling the read. Unsaved evidence leaves the claim in place. */
    public function requireSaved(): void
    {
        if (!$this->pending || $this->saved) { return; }
        $this->write();
        if (!$this->saved) { throw new \RuntimeException('Renewal proof could not be saved.'); }
    }

    private function write(): void
    {
        if ($this->superseded) { return; }
        try {
            $this->saved = $this->withLockedOperation(function (Operation $op): bool {
                if (!$this->matches($op)) { return false; }
                $account = Account::where('server_id', $op->server_id)->first();
                if (!$account || !AccountBindings::binding($account)->matches($this->attempt->binding)) { return false; }
                // A late reply can still prove this attempt even if a different worker now owns its read claim.
                if (!self::persisted($op, $this->identity)) { self::retain($op, $this->identity, $this->clock->now()); }
                return true;
            });
            $this->superseded = !$this->saved;
        } catch (\Throwable) {
            // The registrar result still counts as observed; a failed transaction is retried at the end of the read.
        }
    }

    private function withLockedOperation(\Closure $step): bool
    {
        for ($try = 1; ; $try++) {
            try {
                return FreshTransaction::run(function () use ($step): bool {
                    $op = Operation::whereKey($this->operationId)->lockForUpdate()->first();
                    return $op !== null && $step($op);
                });
            } catch (\Throwable $error) {
                if ($try >= 3 || !MoneyDecisions::contention($error)) { throw $error; }
            }
        }
    }

    private static function retain(Operation $op, string $identity, \DateTimeImmutable $now): void
    { self::savePolicy($op, [...$op->policy, 'renewal_proof' => ['identity' => $identity, 'at' => $now->format(DATE_ATOM)]]); }

    private static function savePolicy(Operation $op, array $policy): void
    {
        $timestamps = $op->timestamps;
        $op->timestamps = false;
        try {
            $op->policy = $policy;
            if (!$op->saveQuietly()) { throw new \RuntimeException('Renewal read evidence could not be saved.'); }
        } finally { $op->timestamps = $timestamps; }
    }

    public static function held(Operation $op): bool
    {
        $identity = RegistrarAnswers::identity($op);
        return self::persisted($op, $identity) || self::legacyPositive($op, $identity);
    }

    private static function persisted(Operation $op, string $identity): bool
    { return self::sameIdentity($op->policy['renewal_proof'] ?? null, 'identity', $identity); }

    /** Retain pre-upgrade positive observations before a status overwrite or a staff credit decision. */
    public static function promote(Operation $op, \DateTimeImmutable $now): void
    {
        if ($op->action !== 'renew' || ($op->attempt['seal'] ?? null) !== 'sealed') { return; }
        $identity = RegistrarAnswers::identity($op);
        if (!self::persisted($op, $identity) && self::legacyPositive($op, $identity)) { self::retain($op, $identity, $now); }
    }

    private static function sameIdentity(mixed $saved, string $key, string $identity): bool
    { return is_array($saved) && is_string($saved[$key] ?? null) && hash_equals($identity, $saved[$key]); }

    private static function legacyPositive(Operation $op, string $identity): bool
    {
        if ($op->action !== 'renew' || ($op->attempt['seal'] ?? null) !== 'sealed') { return false; }
        $before = $op->base_expires_at?->format('Y-m-d');
        $status = $op->policy['status_check'] ?? null;
        if (self::sameIdentity($status, 'attempt_identity', $identity)) {
            $facts = $status['facts'] ?? null;
            if (($facts['owned'] ?? null) === true && (($facts['charge_confirmed'] ?? null) === true
                || self::laterDate($facts['expiry'] ?? null, $before, $facts['expiry_precision'] ?? null))) { return true; }
        }

        $order = $op->policy['order_unproven'] ?? null;
        if ($op->registrar_key !== 'enom' || !self::sameIdentity($order, 'identity', $identity) || ($order['owned'] ?? null) !== true
            || $op->provider_order_id === null || ($order['order_reference'] ?? null) !== $op->provider_order_id
            || ($op->attempt['renewal']['order_reference'] ?? null) !== $op->provider_order_id) { return false; }
        $reads = $order['expiry_reads'] ?? null;
        foreach ([$order['expiry'] ?? null, ...(is_array($reads) ? $reads : [])] as $expiry) {
            if (self::laterDate($expiry, $before, 'date')) { return true; }
        }
        return false;
    }

    private static function laterDate(mixed $expiry, ?string $before, mixed $precision): bool
    {
        $format = match ($precision) {
            'date' => 'Y-m-d',
            'local' => 'Y-m-d\TH:i:s',
            'instant' => 'Y-m-d\TH:i:sP',
            default => null,
        };
        if ($before === null || !is_string($expiry) || $format === null || str_contains($expiry, "\0")) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $expiry, new \DateTimeZone('UTC'));
        return $date !== false && $date->format($format) === $expiry && $date->format('Y-m-d') > $before;
    }

    /** Holding and review stamps omit proof, so a check's own proof write never makes its save stale. */
    public static function stampRow(Operation $op): array
    {
        $row = $op->getRawOriginal();
        try { $policy = $op->policy; } catch (\Illuminate\Contracts\Encryption\DecryptException) { return $row; }
        if (is_array($policy)) { unset($policy['renewal_proof']); $row['policy'] = $policy; }
        return $row;
    }
}
