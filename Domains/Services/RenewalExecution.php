<?php
// Enom d1392a8 managed renewal execution; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DateEvidence, Disposition, ExpiryPrecision, Money, Query, RenewalCode, RenewalRequest, RequestBudget, RequestContext, RequestOrigin, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** One sealed attempt. A durable row, request or admission decision is never a live grant. */
final class RenewalExecution
{
    private \WeakMap $grants;
    public function __construct(private readonly AccountBindings $bindings, private readonly RenewalAuthority $authority,
        private readonly RenewalAdmission $admission, private readonly RenewalAcceptance $accepted,
        private readonly WriteFeatures $features, private readonly Clock $clock) { $this->grants = new \WeakMap; }
    public function run(int $id, RenewalWriter $writer): Operation
    {
        return EntryDeadline::run($this->clock, fn ($finishCap) => $this->runEntered($id, $writer, $finishCap));
    }
    private function runEntered(int $id, RenewalWriter $writer, \DateTimeImmutable $finishCap): Operation
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        $capture = DB::transaction(function () use ($seed, $id) {
            $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
            if ($op?->action === 'renew' && $op->state === 'running' && (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now())) {
                $op->update(['state' => 'uncertain', 'lease_expires_at' => null, 'safe_code' => 'stale_claim',
                    'policy' => [...$op->policy, 'renewal_execution' => ['staff_alert' => true, 'reason' => 'stale_claim', 'refundable_later' => false]]]); return null;
            }
            if (!$op || $op->action !== 'renew' || !in_array($op->state, ['awaiting_payment', 'queued'], true) || !RenewalCycles::virgin($op)) { return null; }
            if (isset($op->policy['renewal_admission']['until']) && new \DateTimeImmutable($op->policy['renewal_admission']['until']) > $this->clock->now()) { return null; }
            if ($op->funding_basis === 'paid_invoice' && $l['invoices']->firstWhere('id', $op->invoice_id)?->status === 'pending') { return null; }
            try { $this->configured($l); } catch (\Throwable) { $this->hold($op, RenewalCode::Acceptance->value, true); return null; }
            try { $this->current($op, $l); } catch (\Throwable) { $this->hold($op, 'renewal_funding_or_authority'); return null; }
            $claim = bin2hex(random_bytes(16));
            $op->update(['policy' => [...$op->policy, 'renewal_admission' => ['key' => $claim, 'until' => $this->clock->now()->modify('+360 seconds')->format(DATE_ATOM)]]]);
            $d = $l['domain']; return [new RenewalRequest($d->id, $d->authority_generation, $d->confirmed_revision,
                $d->provider_expires_at->format('Y-m-d'), $d->provider_domain_id, (int) $op->term, $op->operation_key), $claim];
        });
        if (!$capture) { return Operation::findOrFail($id); }
        [$capture, $admissionClaim] = $capture;
        // The account slot is reserved once. The second complete assessment refreshes every provider fact.
        $deadline = min($finishCap, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+250 seconds'));
        $first = $this->admission->check($capture, $deadline);
        $descriptor = null; $context = null;$reservedContext=null;
        try {
            if ($first->code !== RenewalCode::Admitted) { throw new RenewalRefused($first->code); }
            [$binding, $name, $stamp] = $this->authority->capture($capture);
            $ready = $this->admission->refresh($capture, $deadline);
            if ($ready->code !== RenewalCode::Admitted || !$ready->cost || !$ready->validUntil) { throw new RenewalRefused($ready->code); }
            $until = min($deadline, $first->validUntil, $ready->validUntil);
            $reservedContext=new RequestContext($binding,RequestOrigin::Background,$until,new RequestBudget(1),$this->clock,Runtime::limiter());
            return $reservedContext->withReservedWire(function()use($seed,$id,$binding,$name,$stamp,$ready,$until,$writer,$admissionClaim,$reservedContext,&$descriptor,&$context) {
            [$descriptor, $context] = DB::transaction(function () use ($seed, $id, $binding, $name, $stamp, $ready, $until, $writer, $admissionClaim,$reservedContext) {
                $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id); $d = $l['domain'];
                if ($l['stamp'] !== $stamp || !$op || ($op->policy['renewal_admission']['key'] ?? null) !== $admissionClaim
                    || new \DateTimeImmutable($op->policy['renewal_admission']['until']) <= $this->clock->now() || !RenewalCycles::virgin($op) || !in_array($op->state, ['awaiting_payment', 'queued'], true)) { throw new \DomainException('Renewal changed.'); }
                $funding = $this->current($op, $l); $writer->requirePolicy($binding, $this->features, $this->accepted);
                $cap = $op->policy['renewal']['cost_cap'] ?? null;
                if (!$cap || $cap['currency'] !== 'USD' || WriteMoney::compare($ready->cost->amount, (new Money($cap['amount'], 'USD'))->amount) > 0) {
                    $d->update(['renewal_policy' => [...$d->renewal_policy, 'automatic_spend_paused' => true]]);
                    $this->hold($op, 'renewal_cost_overrun'); return [null, null];
                }
                if ($until < $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+25 seconds')) { throw new \DomainException('Renewal admission expired.'); }
                $reservedContext->requireReservedCapacity();$claim = bin2hex(random_bytes(16)); $digest = hash('sha256', json_encode([$op->operation_key, $op->policy['renewal'], $op->payload], JSON_THROW_ON_ERROR));
                $op->update(['state' => 'running', 'started_at' => $this->clock->now(), 'lease_expires_at' => $this->clock->now()->modify('+360 seconds'),
                    'request_fingerprint' => $digest, 'funding' => [...($op->funding ?? []), 'claim' => $funding, 'seal' => $funding],
                    'attempt' => ['claim_key' => $claim, 'seal' => 'sealed', 'dispatch_attempted_at' => $this->clock->now()->format(DATE_ATOM),
                        'send_state' => 'possibly_sent', 'authorized_cost' => ['amount' => $ready->cost->amount, 'currency' => 'USD'], 'renewal_mode' => $ready->status->value]]);
                $descriptor = new AdmittedOperation($binding, new Query($name, Action::Renew, (int) $op->term), $op->operation_key, $claim,
                    $d->authority_generation, $claim, $digest, $ready->cost, new DateEvidence($op->base_expires_at->format('Y-m-d'), ExpiryPrecision::Date, 'renewal_base'));
                $context = $reservedContext;
                $fresh = HoldingLocks::take($d); $this->grants[$descriptor] = [$context, $fresh['stamp'], $id, $ready->status->value, $writer];
                return [$descriptor, $context];
            });
            return $descriptor?$this->dispatch($seed,$id,$descriptor,$context,$writer):Operation::findOrFail($id);
            });
        } catch (\Throwable $error) {
            DB::transaction(function () use ($seed, $id, $admissionClaim, $error) { $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
                if ($op && RenewalCycles::virgin($op) && ($op->policy['renewal_admission']['key'] ?? null) === $admissionClaim) {
                    $reason = $error instanceof RenewalRefused ? $error->reason : ($error instanceof ReadDeferred?RenewalCode::Rate:RenewalCode::Changed);
                    $retry = in_array($reason, [RenewalCode::Rate, RenewalCode::Reads, RenewalCode::Balance, RenewalCode::Changed, RenewalCode::Acceptance], true);
                    $policy = $op->policy; unset($policy['renewal_admission']); $op->update(['policy' => $policy]);
                    $this->hold($op, $reason->value, $retry);
                } });
        }
        return Operation::findOrFail($id);
    }
    private function dispatch($seed,int $id,AdmittedOperation $descriptor,RequestContext $context,RenewalWriter $writer): Operation
    {
        $result = null;
        try { $result = $writer->renew($descriptor, $context); } catch (\Throwable) {} finally { unset($this->grants[$descriptor]); }
        return DB::transaction(function () use ($seed, $id, $descriptor, $result, $context) {
            $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
            if ($op->state === 'succeeded' || ($op->attempt['claim_key'] ?? null) !== $descriptor->claimKey || array_key_exists('execution', $op->attempt)) { return $op; }
            try { $result?->binding->requireMatch($descriptor->binding); } catch (\Throwable) { $result = null; }
            $a = $op->attempt; $a['execution'] = $result?->jsonSerialize();
            $facts = $result?->evidence()->renewalExecution;
            if ($facts && $facts->query->matches($descriptor->query)) { $a['renewal'] = ['query' => ['name' => $facts->query->name, 'years' => $facts->query->years], 'order_reference' => $facts->orderReference]; }
            $unsent = $result?->sendState === SendState::DefinitelyUnsent;
            $accepted = $op->state === 'running' && $context->deadline > $this->clock->now() && $result && in_array($result->disposition, [Disposition::Accepted, Disposition::Pending], true);
            $op->update(['attempt' => $a, 'provider_order_id' => $facts?->orderReference, 'state' => $op->state !== 'running' ? $op->state : ($unsent ? 'held' : ($accepted ? 'pending' : 'uncertain')),
                'safe_code' => $op->state !== 'running' ? $op->safe_code : ($unsent ? 'renewal_definitely_unsent' : 'renewal_confirmation_required'), 'lease_expires_at' => null,
                'policy' => [...$op->policy, 'renewal_execution' => ['staff_alert' => true, 'reason' => $unsent ? 'renewal_definitely_unsent' : 'awaiting_unique_charge', 'refundable_later' => $unsent && $this->hasPayment($op)]]]); return $op;
        });
    }
    /** Exact-object consume at the last local boundary, after final reads and before the single wire call. */
    public function consume(AdmittedOperation $op, RequestContext $context, RenewalWriter $writer): string
    {
        OperationClaims::outside(); $grant = $this->grants[$op] ?? null; unset($this->grants[$op]);
        if (!$grant || $grant[0] !== $context || $grant[4] !== $writer) { throw new \DomainException('No live renewal grant.'); }
        try { return DB::transaction(function () use ($op, $context, $grant, $writer) {
            $row = Operation::findOrFail($grant[2]); $l = HoldingLocks::take($row->domain()->firstOrFail()); $row = $l['operations']->firstWhere('id', $row->id);
            $funding = $this->current($row, $l); $writer->requirePolicy($op->binding, $this->features, $this->accepted);
            if ($l['stamp'] !== $grant[1] || $row->state !== 'running' || ($row->attempt['seal'] ?? null) !== 'sealed'
                || $row->funding['seal'] !== $funding || $row->attempt['claim_key'] !== $op->claimKey
                || $row->request_fingerprint !== $op->desiredStateDigest || $row->lease_expires_at <= $this->clock->now()
                || $context->deadline < $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+25 seconds')) { throw new \DomainException('Sealed renewal authority changed.'); }
            return $grant[3];
        }); } catch (\Throwable $error) { throw new RenewalUnsent('Live renewal refused before the wire.', previous: $error); }
    }
    private function current(Operation $op, array $l): array
    {
        $this->configured($l);
        if (($l['domain']->renewal_policy['automatic_spend_paused'] ?? false)
            || $op->expected_expires_at > $this->clock->now()->modify('+10 years')) { throw new \DomainException('Renewal spending paused.'); }
        return RenewalFunding::requireCurrent($op, $l);
    }
    private function configured(array $l): void
    {
        try {
            $this->features->requireEnabled(Action::Renew); $this->bindings->requireProcessingLocked($l['server'], $l['account']);
            $this->accepted->requireCurrent(CatalogueAcceptances::binding($l['account']));
        } catch (\Throwable $error) { throw new RenewalRefused(RenewalCode::Acceptance); }
    }
    private function hasPayment(Operation $op): bool
    {
        return $op->funding_basis === 'paid_invoice' && PricingRule::minor((string) \App\Models\InvoiceTransaction::where('invoice_id', $op->invoice_id)->where('status', 'succeeded')->sum('amount')) > 0;
    }
    private function hold(Operation $op, string $reason, bool $retry = false): void
    {
        $op->update(['state' => $retry ? 'queued' : 'held', 'safe_code' => $reason, 'policy' => [...$op->policy,
            'renewal_execution' => ['staff_alert' => true, 'reason' => $reason, 'refundable_later' => !$retry && $this->hasPayment($op)]]]);
    }
}
