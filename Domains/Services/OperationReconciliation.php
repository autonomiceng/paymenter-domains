<?php
// Enom 444f31c reconciliation-registration/provider-disposition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{NameserverEvidence, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Observation never replays a write. No rejection/refund can be inferred from an empty read. */
final class OperationReconciliation
{
    public const POLL_LIMIT=5;
    public function __construct(private readonly OperationClaims $claims, private readonly AccountBindings $bindings, private readonly Clock $clock) {}
    public function run(int $id): Operation
    {
        OperationClaims::outside();
        $capture = $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account): ?array {
            if (!$this->eligible($op)) { return null; }
            $watch=TransferPolling::applies($op);$now=$this->clock->now()->getTimestamp();
            $manual=$watch&&($op->policy['reconciliation']['manual_check']??false);
            if($watch&&!TransferPolling::due($op,$now)){return null;}
            $this->unresolved($op);
            // Eloquent's UPDATE can advance updated_at beyond the model's timestamp.
            // Capture persisted values under these locks before reading the provider.
            $op->refresh();
            try {
                $binding = $this->authority($op, $domain, $all, $funding, $server, $account);
                $attempt = ReconciliationEvidence::attempt($op, $domain, $binding);
                if ($op->action !== 'transfer' && $attempt->replyLost() && !$attempt->historyAcceptance?->covers($attempt, $this->clock->now())) { return null; }
                if (($op->policy['reconciliation']['polls'] ?? 0) >= self::POLL_LIMIT && !TransferPolling::expected($op) && !$manual && !($watch&&TransferPolling::expired($op,$now))) { return null; }
                if($watch){TransferPolling::reserve($op,$now);$op->refresh();}
                $requestedNameservers = null;
                if ($op->action === 'transfer' && $op->registrar_key === 'namesilo' && ($op->payload['nameservers'] ?? [])) {
                    try { $requestedNameservers = NameserverEvidence::requested($op->payload['nameservers'], 'transfer_request', new \DateTimeImmutable($op->attempt['dispatch_attempted_at'])); }
                    catch (\Throwable) { /* Invalid submitted data is not a nameserver fact. */ }
                }
                return [$attempt, self::stamp($op, $domain), self::contract($attempt), $requestedNameservers, $watch, $watch&&TransferPolling::light($op,$now)];
            } catch (\Throwable) {
                if($watch){$p=$op->policy;$p['reconciliation']['staff_alert']=true;$p['reconciliation']['reason']='transfer_authority_changed';if(TransferPolling::expired($op,$now)){$p['reconciliation']['final_check']='done';}$op->update(['policy'=>$p]);}
                return null;
            }
        });
        if (!$capture) { return Operation::findOrFail($id); }
        [$attempt, $stamp, $contract, $requestedNameservers, $watch, $light] = $capture; $proof = null; $nameservers = null; $covered = false; $transferConflict = false; $chargeChecked = false; $transfer = \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Unknown;
        $callCapBlocked = false; $pendingAnomaly = false;
        $limiter=$watch?new TransferReadBudget($this->clock,Runtime::limiter()):null;
        try {
            $context = new RequestContext($attempt->binding, RequestOrigin::Background, $this->clock->now()->modify('+250 seconds'), new RequestBudget(25), $this->clock, $limiter ?? Runtime::limiter());
            $read=function()use($attempt,$context,$light){
                $reader=$this->bindings->reader($attempt->binding->serverId);
                return $light&&$reader instanceof \Paymenter\Extensions\Servers\Domains\Contracts\TransferPoller
                    ?$reader->pollTransfer($attempt,$context):$reader->reconcile($attempt,$context);
            };
            $result=$limiter?$limiter->run($attempt->binding,$read):$read();
            $result->requireAttempt($attempt);
            if ($this->clock->now() < $context->deadline) {
                $facts = $result->result()->evidence();
                $nameservers = $facts->domain?->nameservers ?? $requestedNameservers;
                $covered = $facts->domain?->owned === true && $facts->domain->expiry !== null
                    && ($facts->historyComplete || ($attempt->executionFacts && $attempt->providerReference !== null && count($facts->candidates) > 0));
                $transfer = $facts->transfer;
                $pendingAnomaly=count($facts->candidates)>1 || ($attempt->expectedCharge!==null && count($facts->candidates)===1
                    && $facts->candidates[0]->charge!==null && ($facts->candidates[0]->charge->currency!==$attempt->expectedCharge->currency
                        || self::decimal($facts->candidates[0]->charge->amount)!==self::decimal($attempt->expectedCharge->amount)));
                $transferConflict = in_array($transfer, [\Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Rejected, \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::NeedsAction], true)
                    && ($facts->domain?->owned === true || count($facts->candidates) > 0);
                // A rejection is final only when charges were actually read; otherwise "no candidates" proves nothing.
                $chargeChecked = $facts->historyComplete || ($attempt->executionFacts && $attempt->providerReference !== null);
                if ($attempt->query->action->value === 'transfer' && $transfer !== \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Unknown) { $covered = true; }
                $proof = ReconciliationEvidence::completion($attempt, $facts, $this->clock->now());
            }
        } catch (ReadDeferred) {
            return $watch?$this->deferred($id,$stamp,$limiter->admissions()===0):Operation::findOrFail($id);
        } catch (EnomTestCallLimit) {
            // Enom’s daily guard runs after limiter admission, before the physical exchange.
            if($watch){return $this->deferred($id,$stamp,$limiter->admissions()<=1);}
            $callCapBlocked = true;
        } catch (\Throwable) {} // Safe retained alert; transport/contract failures do not manufacture facts.
        $readStarted=!$watch||$limiter->admissions()>0;
        if (!$covered) {
            if (!$callCapBlocked) {
                return $this->claims->locked($id,function($op,$domain)use($stamp,$watch,$readStarted):Operation {
                    if(!$this->eligible($op)||self::stamp($op,$domain)!==$stamp){return $op;}
                    $policy=TransferPolling::finished($op->policy,$readStarted);
                    if($watch){$policy['reconciliation']['staff_alert']=true;$policy['reconciliation']['reason']=($policy['reconciliation']['final_check']??null)==='done'?'transfer_deadline_passed':'transfer_status_unavailable';}
                    elseif(in_array($op->safe_code,['enom_test_reconciliation_call_cap','enom_test_transfer_reconciliation_call_cap'],true)){$policy['reconciliation']['reason']='reconciliation_required';$op->safe_code='reconciliation_required';}
                    if($op->action==='transfer'){$policy['reconciliation']['read_failed']=true;}
                    $op->update(['policy'=>$policy]);return $op;
                });
            }
            return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($stamp): Operation {
                if (!self::eligible($op) || self::stamp($op, $domain) !== $stamp) { return $op; }
                $policy = $op->policy;
                $policy['reconciliation']['staff_alert'] = true;
                $policy['reconciliation']['reason'] = $op->action==='transfer'?'enom_test_transfer_reconciliation_call_cap':'enom_test_reconciliation_call_cap';
                $op->update(['safe_code' => $policy['reconciliation']['reason'], 'policy' => $policy]);
                return $op;
            });
        }
        $completed = $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($attempt, $stamp, $contract, $proof, $nameservers, $transfer, $transferConflict, $chargeChecked, $pendingAnomaly, $readStarted): Operation {
            if (!$this->eligible($op) || self::stamp($op, $domain) !== $stamp) { return $op; }
            try {
                $binding = $this->authority($op, $domain, $all, $funding, $server, $account);
                if (self::contract(ReconciliationEvidence::attempt($op, $domain, $binding)) !== $contract) { return $op; }
                $binding->requireMatch($attempt->binding);
            } catch (\Throwable) {
                if(TransferPolling::applies($op)){$p=TransferPolling::finished($op->policy,$readStarted);$p['reconciliation']['staff_alert']=true;$p['reconciliation']['reason']='transfer_authority_changed';$op->update(['policy'=>$p]);}
                return $op;
            }
            $policy = TransferPolling::finished($op->policy,$readStarted); $policy['reconciliation']['polls'] = ($policy['reconciliation']['polls'] ?? 0) + 1;
            $policy['reconciliation']['staff_alert']=true;
            if ($op->action === 'transfer') {
                if(in_array($op->safe_code,['enom_test_reconciliation_call_cap','enom_test_transfer_reconciliation_call_cap'],true)){$op->safe_code='reconciliation_required';}
                $policy['reconciliation']['transfer_status'] = $transfer->value; $policy['reconciliation']['read_at'] = $this->clock->now()->getTimestamp(); unset($policy['reconciliation']['read_failed']);
                if ($op->state === 'held') { $op->update(['state' => 'uncertain', 'safe_code' => 'reconciliation_required']); }
                if ($transferConflict) {
                    $policy['reconciliation']['reason'] = $transfer === \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Rejected ? 'transfer_rejection_contradiction' : 'transfer_status_contradiction';
                    $op->update(['policy' => $policy]); return $op;
                }
                if ($transfer === \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::NeedsAction) {
                    $policy['reconciliation']['reason'] = TransferPolling::expired($op,$this->clock->now()->getTimestamp()) ? 'transfer_deadline_passed' : 'transfer_needs_action';
                    $op->update(['state' => 'held', 'safe_code' => 'transfer_needs_action', 'policy' => $policy]); return $op;
                }
                if ($transfer === \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Rejected && !$chargeChecked) {
                    $policy['reconciliation']['reason'] = 'transfer_rejection_unreconciled';
                    $op->update(['policy' => $policy]); return $op;
                }
                if ($transfer === \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Rejected) {
                    $op->update(['state' => 'rejected', 'safe_code' => 'transfer_provider_rejected', 'policy' => $policy, 'completed_at' => $this->clock->now(),
                        'attempt' => [...$op->attempt, 'transfer_terminal' => ['status' => 'rejected', 'send_state' => 'sent', 'operation_key' => $op->operation_key]]]);
                    $domain->update(['status' => 'transfer_rejected']); return $op; // Sent rejection never grants a negative proof or credit.
                }
            }
            if (!$proof && !$pendingAnomaly && $op->action==='transfer' && $transfer===\Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Pending) {
                $expired=TransferPolling::expired($op,$this->clock->now()->getTimestamp());
                $policy['reconciliation']['reason']=$expired?'transfer_deadline_passed':'transfer_pending';
                $policy['reconciliation']['staff_alert']=$expired;
                $op->update(['policy'=>$policy]);return $op;
            }
            if (!$proof) {
                $policy['reconciliation']['reason'] = $op->action==='transfer' ? ($pendingAnomaly?'transfer_charge_unexpected':($transfer===\Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Unknown?'transfer_status_unavailable':'transfer_completion_unconfirmed'))
                    : ($policy['reconciliation']['polls'] >= self::POLL_LIMIT ? 'poll_limit_reached' : 'original_attempt_unresolved');
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
                'observation' => ObservationProjection::acquired($domain, $op->operation_key, $nameservers), 'last_synced_at' => $this->clock->now()]);
            return $op;
        });
        if ($completed->action === 'transfer' && $completed->state === 'succeeded') {
            try { (new TransferWatchEnrollment())->repair((int)$completed->domain_id,(int)$completed->domain->user_id,$this->clock->now()->getTimestamp()); }
            catch (\Throwable $e) { logger()->warning('Transfer watch repair deferred.',['exception_class'=>$e::class]); } // Runtime cursor retries.
        }
        return $completed;
    }
    /** Internal audited resume seam; staff authorization/route belongs to36. No send authority. */
    public function resume(int $id, string $reason, ?StaffOperationReview $review = null): Operation
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason)) { throw new \InvalidArgumentException('Safe resume reason required.'); }
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account, $staff) use ($reason, $review): Operation {
            $l=['domain'=>$domain,'operations'=>$all,'staff'=>$staff]; $review?->check($l);
            if (!$this->eligible($op)) { throw new \DomainException('No unresolved attempt.'); }
            $this->authority($op, $domain, $all, $funding, $server, $account);
            $policy = $op->policy; $history = $policy['reconciliation_resumptions'] ?? [];
            if(TransferPolling::applies($op)&&($policy['reconciliation']['manual_check']??false)){return $op;}
            if (count($history) >= 20) { throw new \DomainException('Resume audit is full.'); }
            $resumption=['reason' => $reason, 'at' => $this->clock->now()->format(DATE_ATOM), 'prior_polls' => $policy['reconciliation']['polls'] ?? 0];
            if(TransferPolling::applies($op)){$policy['reconciliation']['manual_check']=true;$policy['reconciliation']['manual_resumption']=$resumption;$policy['reconciliation']['next_at']=0;}
            else{$policy['reconciliation_resumptions']=[...$history,$resumption];}
            $policy['reconciliation']['polls'] = 0; $policy['reconciliation']['reason'] = 'resumed';
            $op->update(['policy' => $policy]); $review?->record($l); return $op;
        }, $review?->actorId);
    }
    /** Retry zero-wire capacity refusals promptly; preserve pacing after a read and any existing anomaly. */
    private function deferred(int $id,string $stamp,bool $noWire): Operation
    {
        return $this->claims->locked($id,function($op,$domain)use($stamp,$noWire):Operation {
            if(!$this->eligible($op)||self::stamp($op,$domain)!==$stamp){return $op;}
            $p=TransferPolling::defer($op->policy,$this->clock->now()->getTimestamp(),$noWire);$r=$p['reconciliation']??[];
            $ordinary=($r['reason']??null)==='transfer_read_deferred'
                || (($r['reason']??null)==='original_attempt_unresolved'&&($r['polls']??0)===0)
                || (TransferPolling::expected($op)&&($r['transfer_status']??null)==='pending');
            $overdue=TransferPolling::expired($op,$this->clock->now()->getTimestamp());
            if($ordinary||$overdue&&in_array($r['reason']??null,['transfer_deadline_passed','resumed'],true)){
                $p['reconciliation']['staff_alert']=$overdue;$p['reconciliation']['reason']=$overdue?'transfer_deadline_passed':'transfer_read_deferred';
            }
            $op->update(['policy'=>$p]);return $op; // Preserve an existing anomaly or old-registrar action notice.
        });
    }
    private function eligible(Operation $op): bool
    {
        if (!in_array($op->action, ['register', 'transfer'], true)) { return false; }
        if ($op->state === 'running') { return !$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now(); }
        return ($op->state === 'held' && $op->safe_code === 'transfer_needs_action') || $op->state === 'uncertain' || ($op->state === 'pending'
            && ($op->attempt['registration']['financial_completion'] ?? null) === 'awaiting_unique_charge');
    }
    private function unresolved(Operation $op): void
    {
        $policy = $op->policy ?? [];
        if(TransferPolling::applies($op)&&isset($policy['reconciliation'])){
            if($op->state==='running'){$op->update(['state'=>'uncertain','lease_expires_at'=>null,'safe_code'=>'stale_claim']);}
            return;
        }
        $policy['reconciliation'] = [...($policy['reconciliation'] ?? []), 'polls' => $policy['reconciliation']['polls'] ?? 0,
            'staff_alert' => true, 'reason' => $op->state === 'held' && $op->safe_code === 'transfer_needs_action' ? 'transfer_needs_action'
                : (($policy['reconciliation']['polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT ? 'poll_limit_reached' : 'original_attempt_unresolved')];
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
            || $all->contains(static fn ($other) => $other->id !== $op->id && !OperationTerminalProof::safe($other, $domain)
                && !ManagementCorrections::resolvedFor($other, $op, $domain))) {
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
