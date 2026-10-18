<?php
// Enom 444f31c reconciliation-registration/provider-disposition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Observation never replays a write. No rejection/refund can be inferred from an empty read. */
final class OperationReconciliation
{
    public function __construct(private readonly OperationClaims $claims, private readonly AccountBindings $bindings, private readonly Clock $clock) {}
    public function run(int $id): Operation
    {
        OperationClaims::outside();
        $capture = $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account): ?array {
            if (!$this->eligible($op)) { return null; }
            $this->unresolved($op);
            try {
                $binding = $this->authority($op, $domain, $all, $funding, $server, $account);
                $attempt = ReconciliationEvidence::attempt($op, $domain, $binding);
                if ($op->action !== 'transfer' && (!$attempt->executionFacts || $attempt->providerReference === null) && !$attempt->historyAcceptance?->covers($attempt, $this->clock->now())) { return null; }
                if (($op->policy['reconciliation']['polls'] ?? 0) >= 5) { return null; }
                return [$attempt, self::stamp($op, $domain), self::contract($attempt)];
            } catch (\Throwable) { return null; }
        });
        if (!$capture) { return Operation::findOrFail($id); }
        [$attempt, $stamp, $contract] = $capture; $proof = null; $covered = false; $transferConflict = false; $transfer = \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Unknown;
        try {
            $context = new RequestContext($attempt->binding, RequestOrigin::Background, $this->clock->now()->modify('+250 seconds'), new RequestBudget(25), $this->clock);
            $result = $this->bindings->reader($attempt->binding->serverId)->reconcile($attempt, $context);
            $result->requireAttempt($attempt);
            if ($this->clock->now() < $context->deadline) {
                $facts = $result->result()->evidence();
                $covered = $facts->domain?->owned === true && $facts->domain->expiry !== null
                    && ($facts->historyComplete || ($attempt->executionFacts && $attempt->providerReference !== null && count($facts->candidates) > 0));
                $transfer = $facts->transfer;
                $transferConflict = $transfer === \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Rejected
                    && ($facts->domain?->owned === true || count($facts->candidates) > 0);
                if ($attempt->query->action->value === 'transfer' && $transfer !== \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Unknown) { $covered = true; }
                $proof = ReconciliationEvidence::completion($attempt, $facts, $this->clock->now());
            }
        } catch (\Throwable) {} // Safe retained alert; transport/contract failures do not manufacture facts.
        if (!$covered) { return Operation::findOrFail($id); }
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($attempt, $stamp, $contract, $proof, $transfer, $transferConflict): Operation {
            if (!$this->eligible($op) || self::stamp($op, $domain) !== $stamp) { return $op; }
            try {
                $binding = $this->authority($op, $domain, $all, $funding, $server, $account);
                if (self::contract(ReconciliationEvidence::attempt($op, $domain, $binding)) !== $contract) { return $op; }
                $binding->requireMatch($attempt->binding);
            } catch (\Throwable) { return $op; }
            $policy = $op->policy; $policy['reconciliation']['polls'] = ($policy['reconciliation']['polls'] ?? 0) + 1;
            if ($op->action === 'transfer') {
                $policy['reconciliation']['transfer_status'] = $transfer->value;
                if ($transferConflict) {
                    $policy['reconciliation']['reason'] = 'transfer_rejection_contradiction';
                    $op->update(['policy' => $policy]); return $op;
                }
                if ($transfer === \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Rejected) {
                    $op->update(['state' => 'rejected', 'safe_code' => 'transfer_provider_rejected', 'policy' => $policy, 'completed_at' => $this->clock->now(),
                        'attempt' => [...$op->attempt, 'transfer_terminal' => ['status' => 'rejected', 'send_state' => 'sent', 'operation_key' => $op->operation_key]]]);
                    $domain->update(['status' => 'transfer_rejected']); return $op; // Sent rejection never grants a negative proof or credit.
                }
            }
            if (!$proof) {
                $policy['reconciliation']['reason'] = $policy['reconciliation']['polls'] >= 5 ? 'poll_limit_reached' : 'original_attempt_unresolved';
                $op->update(['policy' => $policy]); return $op;
            }
            $funding->completeInitial($op, $domain); // Persistence failures roll back the whole native/domain decision.
            $cost = $proof['charge']; $expected = $attempt->expectedCharge;
            $difference = $expected && ($expected->currency !== 'USD' || rtrim($expected->amount, '0') !== rtrim($cost['amount'], '0'));
            $corrections = ($op->attempt['corrections'] ?? $op->attempt['evidence']['registration']['corrections'] ?? []) !== [] || $proof['observed_corrections'] !== [];
            $below = strcmp(self::decimal($cost['amount']), self::decimal($op->amount)) > 0;
            $policy['reconciliation']['observed_corrections'] = $proof['observed_corrections']; $policy['reconciliation']['staff_alert'] = $difference || $corrections || $below;
            $policy['reconciliation']['reason'] = $below ? 'below_cost' : ($difference ? 'actual_charge_changed' : ($corrections ? 'correction_required' : 'confirmed'));
            $op->update(['state' => 'succeeded', 'safe_code' => $corrections ? 'registration_correction_required' : ($difference ? 'actual_charge_changed' : null),
                'attempt' => [...$op->attempt, 'reconciliation' => $proof], 'cost' => [...($op->cost ?? []), 'actual' => $cost],
                'policy' => $policy, 'provider_order_id' => $proof['order_reference'], 'confirmed_expires_at' => $proof['expiry'],
                'completed_at' => $this->clock->now(), 'lease_expires_at' => null]);
            $domain->update(['status' => 'active', 'provider_expires_at' => $proof['expiry'], 'provider_order_id' => $proof['order_reference'],
                'provider_domain_id' => $proof['provider_domain_id'], 'confirmed_revision' => $domain->confirmed_revision + 1,
                'renewal_policy' => [...($domain->renewal_policy ?? []), 'billing_anchor' => ['expiry' => $proof['expiry'], 'operation_key' => $op->operation_key]],
                'last_synced_at' => $this->clock->now()]);
            return $op;
        });
    }
    /** Internal audited resume seam; staff authorization/route belongs to36. No send authority. */
    public function resume(int $id, string $reason): Operation
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason)) { throw new \InvalidArgumentException('Safe resume reason required.'); }
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($reason): Operation {
            if (!$this->eligible($op)) { throw new \DomainException('No unresolved attempt.'); }
            $this->authority($op, $domain, $all, $funding, $server, $account);
            $policy = $op->policy; $history = $policy['reconciliation_resumptions'] ?? [];
            if (count($history) >= 20) { throw new \DomainException('Resume audit is full.'); }
            $policy['reconciliation_resumptions'] = [...$history, ['reason' => $reason, 'at' => $this->clock->now()->format(DATE_ATOM), 'prior_polls' => $policy['reconciliation']['polls'] ?? 0]];
            $policy['reconciliation']['polls'] = 0; $policy['reconciliation']['reason'] = 'resumed';
            $op->update(['policy' => $policy]); return $op;
        });
    }
    private function eligible(Operation $op): bool
    {
        if (!in_array($op->action, ['register', 'transfer'], true)) { return false; }
        if ($op->state === 'running') { return !$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now(); }
        return $op->state === 'uncertain' || ($op->state === 'pending'
            && ($op->attempt['registration']['financial_completion'] ?? null) === 'awaiting_unique_charge');
    }
    private function unresolved(Operation $op): void
    {
        $policy = $op->policy ?? [];
        $policy['reconciliation'] = [...($policy['reconciliation'] ?? []), 'polls' => $policy['reconciliation']['polls'] ?? 0,
            'staff_alert' => true, 'reason' => ($policy['reconciliation']['polls'] ?? 0) >= 5 ? 'poll_limit_reached' : 'original_attempt_unresolved'];
        $op->update(['policy' => $policy, 'state' => $op->state === 'running' ? 'uncertain' : $op->state,
            'lease_expires_at' => null, 'safe_code' => $op->state === 'running' ? 'stale_claim' : ($op->safe_code ?: 'reconciliation_required')]);
    }
    private function authority($op, $domain, $all, $funding, $server, $account)
    {
        $this->bindings->requireReadableLocked($server, $account); $binding = CatalogueAcceptances::binding($account);
        if ($domain->status !== ($op->action === 'transfer' ? 'transfer_pending' : 'registration_pending') || !$domain->billing_managed || $domain->reservation_name !== $domain->domain
            || $domain->provider_expires_at !== null || $domain->provider_domain_id !== null || $domain->provider_order_id !== null
            || $op->confirmed_expires_at !== null || $op->authority_generation !== $domain->authority_generation
            || $op->account_fingerprint !== $binding->fingerprint || $domain->account_fingerprint !== $binding->fingerprint
            || $op->registrar_key !== $binding->registrarKey || $domain->registrar_key !== $binding->registrarKey
            || (int) $op->server_id !== $binding->serverId || (int) $domain->server_id !== $binding->serverId
            || $all->contains(static fn ($other) => $other->id !== $op->id && !OperationTerminalProof::safe($other, $domain))) {
            throw new \DomainException('Reconciliation authority changed.');
        }
        $funding->requireInitial($op, $domain); return $binding;
    }
    private static function decimal(string $amount): string { return str_pad(explode('.', $amount)[0], 18, '0', STR_PAD_LEFT).str_pad(explode('.', $amount)[1] ?? '', 6, '0'); }
    private static function stamp(Operation $op, Domain $domain): string {
        $facts = static fn ($model) => array_map(static fn ($key) => $model->getAttribute($key), array_keys($model->getAttributes()));
        return hash('sha256', json_encode([$facts($op), $facts($domain)], JSON_THROW_ON_ERROR));
    }
    private static function contract($attempt): string { return json_encode($attempt->historyAcceptance, JSON_THROW_ON_ERROR); }
}
