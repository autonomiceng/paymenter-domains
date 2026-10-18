<?php
// Enom d1392a8 renewal completion; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Bounded observation of the original sealed renewal. Never retries, refunds or invents expiry. */
final readonly class RenewalCompletion
{
    public function __construct(private AccountBindings $bindings, private Clock $clock) {}
    public function run(int $id): Operation
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        $capture = DB::transaction(function () use ($seed, $id) {
            $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
            if (!$op || !$this->eligible($op)) { return null; }
            $policy = $op->policy; $policy['renewal_execution'] = ['staff_alert' => true, 'reason' => ($op->policy['renewal_polls'] ?? 0) >= 5 ? 'poll_limit_reached' : 'original_attempt_unresolved', 'refundable_later' => false];
            $op->update(['state' => $op->state === 'running' ? 'uncertain' : $op->state, 'lease_expires_at' => null, 'policy' => $policy]);
            try {
                $this->bindings->requireReadableLocked($l['server'], $l['account']); RenewalFunding::requireCurrent($op, $l, true);
                $attempt = ReconciliationEvidence::attempt($op, $l['domain'], CatalogueAcceptances::binding($l['account']));
                if ((!$attempt->executionFacts || $attempt->providerReference === null) && !$attempt->historyAcceptance?->covers($attempt, $this->clock->now())) { return null; }
                if (($op->policy['renewal_polls'] ?? 0) >= 5) { return null; }
                return [$attempt, HoldingLocks::take($l['domain'])['stamp']];
            } catch (\Throwable) { return null; }
        });
        if (!$capture) { return Operation::findOrFail($id); }
        [$attempt, $stamp] = $capture; $proof = null; $covered = false;
        try {
            $ctx = new RequestContext($attempt->binding, RequestOrigin::Background, $this->clock->now()->modify('+250 seconds'), new RequestBudget(25), $this->clock);
            $result = $this->bindings->reader($attempt->binding->serverId)->reconcile($attempt, $ctx); $result->requireAttempt($attempt);
            if ($this->clock->now() < $ctx->deadline) {
                $facts = $result->result()->evidence();
                $covered = $facts->domain?->owned === true && $facts->domain->expiry !== null
                    && ($facts->historyComplete || ($attempt->executionFacts && $attempt->providerReference !== null && count($facts->candidates) > 0));
                $proof = ReconciliationEvidence::completion($attempt, $facts, $this->clock->now());
            }
        } catch (\Throwable) {}
        if (!$covered) { return Operation::findOrFail($id); }
        return DB::transaction(function () use ($seed, $id, $stamp, $proof, $attempt) {
            $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id); $d = $l['domain'];
            if (!$op || !$this->eligible($op) || $l['stamp'] !== $stamp || ($proof && $proof['provider_domain_id'] !== $d->provider_domain_id)) { return $op; }
            try { $this->bindings->requireReadableLocked($l['server'], $l['account']); RenewalFunding::requireCurrent($op, $l, true); }
            catch (\Throwable) { return $op; }
            $policy = $op->policy; $policy['renewal_polls'] = ($policy['renewal_polls'] ?? 0) + 1;
            if (!$proof) {
                $policy['renewal_execution']['reason'] = $policy['renewal_polls'] >= 5 ? 'poll_limit_reached' : 'original_attempt_unresolved';
                $op->update(['policy' => $policy]); return $op;
            }
            $cost = $proof['charge']; $cap = $op->policy['renewal']['cost_cap'];
            $over = WriteMoney::compare($cost['amount'], $cap['amount']) > 0;
            $changed = WriteMoney::compare($cost['amount'], $attempt->expectedCharge->amount) !== 0;
            $below = WriteMoney::compare($cost['amount'], $op->amount) > 0; $corrections = $proof['observed_corrections'] !== [];
            $policy['renewal_execution'] = ['staff_alert' => $over || $changed || $below || $corrections,
                'reason' => $over ? 'actual_cost_overrun' : ($changed ? 'actual_charge_changed' : ($below ? 'below_cost' : 'confirmed')), 'refundable_later' => false];
            $op->update(['state' => 'succeeded', 'safe_code' => $changed ? 'actual_charge_changed' : null, 'policy' => $policy,
                'attempt' => [...$op->attempt, 'reconciliation' => $proof], 'cost' => ['actual' => $cost], 'provider_order_id' => $proof['order_reference'],
                'confirmed_expires_at' => $proof['expiry'], 'completed_at' => $this->clock->now(), 'lease_expires_at' => null]);
            $d->update(['status' => 'active', 'provider_expires_at' => $proof['expiry'], 'provider_order_id' => $proof['order_reference'],
                'confirmed_revision' => $d->confirmed_revision + 1, 'last_synced_at' => $this->clock->now(),
                'renewal_policy' => [...$d->renewal_policy, 'automatic_spend_paused' => $over || ($d->renewal_policy['automatic_spend_paused'] ?? false),
                    'billing_anchor' => ['expiry' => $proof['expiry'], 'operation_key' => $op->operation_key]]]);
            return $op;
        });
    }
    /** Explicit bounded read-only recovery; original paid uncertainty and attempt remain unchanged. */
    public function resume(int $id, StaffOperationReview $review): Operation
    {
        OperationClaims::outside(); $seed=Operation::findOrFail($id)->domain()->firstOrFail();
        return DB::transaction(function()use($seed,$id,$review){
            $l=HoldingLocks::take($seed,$review->actorId);$review->check($l);$op=$l['operations']->firstWhere('id',$id);
            if(!$op||!$this->eligible($op)){throw new \DomainException('No unresolved sealed renewal.');}
            $this->bindings->requireReadableLocked($l['server'],$l['account']);RenewalFunding::requireCurrent($op,$l,true);
            ReconciliationEvidence::attempt($op,$l['domain'],CatalogueAcceptances::binding($l['account']));
            $policy=$op->policy;$history=$policy['renewal_resumptions']??[];
            if(!is_array($history)||!array_is_list($history)||count($history)>=20){throw new \DomainException('Renewal read resume limit reached.');}
            $policy['renewal_resumptions']=[...$history,['prior_polls'=>$policy['renewal_polls']??0,'at'=>$this->clock->now()->format(DATE_ATOM)]];
            $policy['renewal_polls']=0;$policy['renewal_execution']=['staff_alert'=>true,'reason'=>'resumed','refundable_later'=>false];
            $op->update(['policy'=>$policy]);$review->record($l);return $op;
        });
    }
    private function eligible(Operation $op): bool
    {
        return $op->action === 'renew' && (($op->state === 'running' && (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now()))
            || in_array($op->state, ['pending', 'uncertain'], true)) && ($op->attempt['seal'] ?? null) === 'sealed';
    }
}
