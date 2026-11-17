<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, DomainSnapshot, OrderEvidence};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
/** Renewal evidence a registrar read returns (a matching charge or a later owned expiry) is saved for its attempt the moment
 * the read returns it, before any locked money step. Its own write locks only the operation row, so it never waits on or
 * blocks checkout or receipt locks, and no retry, deferral or claim can drop it. It only adds proof, never removes it. */
final class RenewalProof
{
    private bool $saved = false;
    private bool $pending = false;
    public function __construct(private readonly int $operationId, private readonly string $identity, private readonly AccountBinding $binding,
        private readonly string $name, private readonly ?string $before, private readonly Clock $clock) {}
    /** The RequestContext observer: called with each owned expiry and charge candidate a read returns. */
    public function saw(DomainSnapshot|OrderEvidence $seen): void
    {
        if ($this->saved || !$seen->binding->matches($this->binding)) { return; }
        $positive = $seen instanceof OrderEvidence ? $seen->query->name === $this->name
            : $seen->name === $this->name && $seen->owned === true && $this->before !== null && $seen->expiry !== null && substr($seen->expiry->value, 0, 10) > $this->before;
        if ($positive) { $this->pending = true; $this->write(); }
    }
    /** After the read: a proof write that failed is tried once more; still unsaved, the check stops before it counts anything. */
    public function requireSaved(): void
    {
        if ($this->pending && !$this->saved) { $this->write(); }
        if ($this->pending && !$this->saved) { throw new \RuntimeException('Renewal proof could not be saved.'); }
    }
    private function write(): void
    {
        for ($try = 0; $try < 3 && !$this->saved; $try++) {
            try {
                FreshTransaction::run(function (): void {
                    $op = Operation::whereKey($this->operationId)->lockForUpdate()->first();
                    // Bound to the attempt the read was for and to the registrar account it read.
                    if (!$op || !hash_equals(RegistrarAnswers::identity($op), $this->identity)
                        || !AccountBindings::binding(Account::where('server_id', $op->server_id)->sole())->matches($this->binding)) { return; }
                    if (self::held($op)) { return; }
                    $op->policy = [...($op->policy ?? []), 'renewal_proof' => ['identity' => $this->identity, 'at' => $this->clock->now()->format(DATE_ATOM)]];
                    $op->timestamps = false; $op->saveQuietly();
                });
                $this->saved = true;
            } catch (\Throwable $e) { if (!MoneyDecisions::contention($e)) { return; } }
        }
    }
    /** Saved proof for the operation's current attempt. */
    public static function held(Operation $op): bool
    {
        $seen = $op->policy['renewal_proof'] ?? null;
        return is_array($seen) && is_string($seen['identity'] ?? null) && hash_equals(RegistrarAnswers::identity($op), $seen['identity']);
    }
    /** Holding and review stamps leave out saved proof, so a check's own proof write never makes its save stale. */
    public static function stampRow(Operation $op): array
    {
        $row = $op->getRawOriginal();
        try { $policy = $op->policy; } catch (\Illuminate\Contracts\Encryption\DecryptException) { return $row; }
        if (is_array($policy)) { unset($policy['renewal_proof']); $row['policy'] = $policy; }
        return $row;
    }
}
