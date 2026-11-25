<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Dispatch;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\{RenewalDecision, RenewalStanding};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, FreshTransaction, HoldingLocks, OperationClaims, RenewalAcceptance, RenewalFundingLedger, WriteFeatures};
/** Process-local live renewal grants, one per sealed request object. A durable row or admission decision is never a grant.
 * The gate reads the attempt itself when it issues a grant and again when the writer consumes it. No caller supplies the check. */
final class DispatchGate
{
    private \WeakMap $grants;
    private readonly RenewalStanding $standing;
    public function __construct(AccountBindings $bindings, private readonly RenewalAcceptance $accepted,
        private readonly WriteFeatures $features, private readonly Clock $clock)
    { $this->grants = new \WeakMap; $this->standing = new RenewalStanding($bindings, $accepted, $features, $clock); }
    /** Issues the one grant for a sealed, funded attempt whose account and deadline match. Any other attempt throws.
     * The seal calls this inside its own transaction, so the read joins it and sees the sealed row. */
    public function issue(AdmittedOperation $op, RequestContext $context, RenewalWriter $writer): void
    {
        $read = function () use ($op, $context, $writer) {
            [$row, $l] = $this->locked((int) Operation::where('operation_key', $op->operationKey)->value('id'));
            $this->verify($row, $l, $op, $context, $writer, $l['stamp'], $row->transition_revision);
            return ['context' => $context, 'writer' => $writer, 'id' => $row->id, 'stamp' => $l['stamp'],
                'revision' => $row->transition_revision, 'mode' => $row->attempt['renewal_mode']];
        };
        $this->grants[$op] = DB::transactionLevel() > 0 ? $read() : FreshTransaction::run($read);
    }
    public function revoke(AdmittedOperation $op): void
    { unset($this->grants[$op]); }
    /** Exact-object consume at the last local boundary: the grant is gone before its check runs, so a failed check is never retried.
     * Returns the send mode. A failed check becomes RenewalUnsent; a missing or mismatched grant stays a DomainException. */
    public function consume(AdmittedOperation $op, RequestContext $context, RenewalWriter $writer): string
    {
        OperationClaims::outside(); $grant = $this->grants[$op] ?? null; unset($this->grants[$op]);
        if (!$grant || $grant['context'] !== $context || $grant['writer'] !== $writer) { throw new \DomainException('No live renewal grant.'); }
        try {
            return FreshTransaction::run(function () use ($op, $context, $writer, $grant) {
                [$row, $l] = $this->locked($grant['id']);
                $this->verify($row, $l, $op, $context, $writer, $grant['stamp'], $grant['revision']);
                return $grant['mode'];
            });
        } catch (\Throwable $error) { throw new RenewalUnsent('Live renewal refused before the wire.', previous: $error); }
    }
    /** @return array{Operation, array} the operation row read under its holding locks. */
    private function locked(int $id): array
    {
        $row = Operation::findOrFail($id); $l = HoldingLocks::take($row->domain()->firstOrFail());
        return [$l['operations']->firstWhere('id', $row->id), $l];
    }
    private function verify(Operation $row, array $l, AdmittedOperation $op, RequestContext $context, RenewalWriter $writer,
        string $stamp, int $revision): void
    {
        $funding = $this->standing->current($row, $l); $writer->requirePolicy($op->binding, $this->features, $this->accepted);
        AccountBindings::binding($l['account'])->requireMatch($op->binding); $op->binding->requireMatch($context->binding);
        $matchesFunding = $row->funding_basis === 'paid_invoice'
            ? RenewalFundingLedger::entries($row) === $funding['entries']
            : ($row->funding['seal'] ?? null) === $funding;
        $attempt = $row->attempt ?? [];
        $reservationMatches = $l['stamp'] === $stamp && $row->action === 'renew' && $row->operation_key === $op->operationKey
            && ($attempt['seal'] ?? null) === 'sealed' && ($attempt['claim_key'] ?? null) === $op->claimKey
            && $row->request_fingerprint === $op->desiredStateDigest;
        if (!RenewalDecision::canSend($revision, $row->transition_revision, $row->state,
            $reservationMatches, $matchesFunding, $row->lease_expires_at, $context->deadline, $this->clock->now())) {
            throw new \DomainException('Sealed renewal authority changed.');
        }
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('Dispatch grants cannot be persisted.'); }
    public function __clone(): void { throw new \LogicException('Dispatch grants cannot be cloned.'); }
}
