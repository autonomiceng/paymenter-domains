<?php
// Enom d1392a8 managed renewal execution; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Renewal\Execution;
use Illuminate\Support\Facades\{DB, Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AdmittedOperation, DateEvidence, ExpiryPrecision, Money, Query, RenewalCode, RenewalDecision as AdmissionDecision, RenewalRequest, RequestBudget, RequestContext, RequestOrigin, SendFailure, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Registrars\Dispatch\{RenewalUnsent, RenewalWriter};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, ComplimentaryCosts, EnomLateRenewal, EnomTestCallLimit, EnomTestCalls, EnomTestRenewal, EnomTestWriteReservation, EntryDeadline, FreshTransaction, FundsWaits, HoldingLocks, LateRenewalQuote, MoneyDecisions, MoneyRequest, ObligationAlerts, OperationClaims, ReadDeferred, RenewalAcceptance, RenewalAuthority, RenewalBusy, RenewalCycles, RenewalFunding, RenewalFundingLedger, RenewalHold, RenewalRefused, Runtime, WriteFeatures, WriteHeld, WriteMoney};
/** One sealed attempt. A durable row, request or admission decision is never a live grant. */
final class RenewalExecution
{
    /** Renewal steps deferred by database contention. The runtime turn hands them back to the operation queue. */
    public const KIND = 'renewal';
    /** Live grants, one per sealed request object, minted only by the seal transition. */
    private \WeakMap $grants;
    private readonly RenewalStanding $standing;
    public function __construct(private readonly AccountBindings $bindings, private readonly RenewalAuthority $authority,
        private readonly RenewalAdmission $admission, private readonly RenewalAcceptance $accepted,
        private readonly WriteFeatures $features, private readonly Clock $clock)
    { $this->standing = new RenewalStanding($bindings, $accepted, $features, $clock); $this->grants = new \WeakMap; }
    public function run(int $id, RenewalWriter $writer): Operation
    {
        $work=fn()=>EntryDeadline::run($this->clock, fn ($finishCap) => $this->runEntered($id, $writer, $finishCap));
        $op=Operation::findOrFail($id);$a=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->first();
        try{$b=$a?AccountBindings::binding($a):null;}catch(\Throwable){$b=null;}
        return $b&&$b->registrarKey==='enom'&&EnomTestRenewal::enabled($b)?EnomTestWriteReservation::run($b,'renewal',$id,$work):$work();
    }
    private function runEntered(int $id, RenewalWriter $writer, \DateTimeImmutable $finishCap): Operation
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        $capture = $this->decide($seed, $id, fn (array $l) => $this->admit($l, $id));
        if (!$capture) { return Operation::findOrFail($id); }
        [$capture, $admissionClaim] = $capture;
        // The account slot is reserved once. The second complete assessment refreshes every provider fact.
        $deadline = min($finishCap, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+250 seconds'));
        $refusals=EnomTestCalls::refusalGeneration();$first = $this->admission->check($capture, $deadline);
        $ready=null;
        try {
            if ($first->code !== RenewalCode::Admitted) { throw new RenewalRefused($first->code); }
            [$binding] = $this->authority->capture($capture);
            $guardContext=new RequestContext($binding,RequestOrigin::Background,min($deadline,$first->validUntil),new RequestBudget(1),$this->clock,Runtime::limiter());
            $writer->guardAccount($guardContext);
            // First-use pinning changes retained settings; capture the financial stamp afterwards.
            [$binding, $name, $stamp] = $this->authority->capture($capture);
            $ready = $this->admission->refresh($capture, $deadline);
            if ($ready->code !== RenewalCode::Admitted || !$ready->cost || !$ready->validUntil) { throw new RenewalRefused($ready->code); }
            $until = min($deadline, $first->validUntil, $ready->validUntil);
            if (($frozen = LateRenewalQuote::deadline(Operation::findOrFail($id))) !== null) { $until = min($until, $frozen); }
            $reservedContext=new RequestContext($binding,RequestOrigin::Background,$until,new RequestBudget(1),$this->clock,Runtime::limiter());
            return $writer->withinReservation(Operation::findOrFail($id),fn()=>$reservedContext->withReservedWire(function()use($seed,$id,$binding,$name,$stamp,$ready,$until,$writer,$admissionClaim,$reservedContext) {
            EnomTestWriteReservation::reserve($binding,'renewal',$ready->cost);
            $writer->reservePurchase($name,(int)Operation::findOrFail($id)->term,$ready->cost);
            $descriptor = FreshTransaction::run(fn () => $this->seal(HoldingLocks::take($seed), $id, $binding, $name, $stamp, $ready, $until, $writer, $admissionClaim, $reservedContext));
            return $descriptor?$this->dispatch($seed,$id,$descriptor,$reservedContext,$writer):Operation::findOrFail($id);
            }));
        } catch (\Throwable $error) {
            $this->decide($seed, $id, fn (array $l) => $this->refuse($l, $id, $admissionClaim, $error, $refusals, $first, $ready));
        }
        return Operation::findOrFail($id);
    }
    /** Admission under the holding locks: a claim revision and the renewal request, or null after any hold. */
    private function admit(array $l, int $id): ?array
    {
        $op = $l['operations']->firstWhere('id', $id);
        if ($op?->action !== 'renew') { return null; }
        $next = RenewalDecision::admission($op->state, $op->lease_expires_at, $op->renewal_admission_until, $this->clock->now());
        if ($next === 'quarantine') {
            $op->update(['state' => 'uncertain', 'lease_expires_at' => null, 'safe_code' => 'stale_claim',
                'policy' => [...$op->policy, 'renewal_execution' => ['staff_alert' => true, 'reason' => 'stale_claim', 'refundable_later' => false]]]); return null;
        }
        if ($next !== 'admit' || !RenewalCycles::registrarVirgin($op)) { return null; }
        if (FundsWaits::elapsed($op,$this->clock->now())) { return null; }
        if ($op->funding_basis === 'paid_invoice' && $l['invoices']->firstWhere('id', $op->invoice_id)?->status === 'pending') { return null; }
        try { $this->standing->current($op, $l); }
        catch (RenewalHold $held) { $this->hold($op, $l, $held->reason, $held->reason === EnomLateRenewal::PAUSED); return null; }
        catch (RenewalRefused $refused) { $this->hold($op, $l, $refused->reason->value, $refused->reason === RenewalCode::Acceptance); return null; }
        catch (\PDOException|RenewalBusy $e) { throw $e; }
        catch (\Throwable) { $this->hold($op, $l, 'renewal_funding_or_authority'); return null; }
        // Enom's Extend contract requires OverrideOrder for another renewal within 24 hours.
        // Wait from confirmed completion (later than the send), never bypass replay protection.
        // The time it may go is the latest such completion + 24 hours, stored once so pages show a fixed time.
        if ($op->registrar_key === 'enom' && ($until = RenewalRepeatWait::next($l['domain'], $l['operations'], $this->clock->now(), AccountBindings::binding($l['account']))) !== null) {
            $this->hold($op, $l, RenewalCode::Repeat->value, true, ['staff_alert' => false, 'eligible_at' => $until->format(DATE_ATOM)]);
            return null;
        }
        $claim = $op->transition_revision + 1;
        $op->update(['transition_revision' => $claim, 'renewal_admission_until' => $this->clock->now()->modify('+360 seconds')]);
        $d = $l['domain']; return [new RenewalRequest($d->id, $d->authority_generation, $d->confirmed_revision,
            $d->provider_expires_at->format('Y-m-d'), $d->provider_domain_id, (int) $op->term, $op->operation_key), $claim];
    }
    /** Seals the attempt under the holding locks and issues its one live grant. Null means a local hold was recorded. */
    private function seal(array $l, int $id, AccountBinding $binding, $name, $stamp, AdmissionDecision $ready, \DateTimeInterface $until,
        RenewalWriter $writer, int $admissionClaim, RequestContext $reservedContext): ?AdmittedOperation
    {
        $op = $l['operations']->firstWhere('id', $id); $d = $l['domain'];
        if ($l['stamp'] !== $stamp || !$op || $op->transition_revision !== $admissionClaim
            || $op->renewal_admission_until <= $this->clock->now() || !RenewalDecision::awaitsAdmission($op->state) || !RenewalCycles::registrarVirgin($op)) { throw new \DomainException('Renewal changed.'); }
        $funding = $this->standing->current($op, $l); $writer->requirePolicy($binding, $this->features, $this->accepted);
        // Both admissions proved sufficient registrar funds; a later hold has its own cause.
        if(\Illuminate\Support\Facades\Schema::hasTable('domains_obligation_alerts')){
            DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','renewal_funds')->whereNull('resolved_at')
                ->update(['resolved_at'=>$this->clock->now()->getTimestamp()]);
        }
        $cap = $op->policy['renewal']['cost_cap'] ?? null;
        if(isset($op->policy['account_complimentary'])&&$cap){
            try{ComplimentaryCosts::require($op,$ready->cost,$cap['amount']);}catch(WriteHeld){$this->hold($op->refresh(),$l,'registrar_price_increased');return null;}
            $cap['amount']=ComplimentaryCosts::cap($op,$cap['amount']);
        }
        $late = $op->policy['renewal']['late'] ?? null;
        if ($ready->status === \Paymenter\Extensions\Servers\Domains\Data\RenewalStatus::Grace && EnomTestRenewal::enabled($binding)) {
            if (!$late || !EnomLateRenewal::accepted($binding) || $ready->providerExpiry !== $late['expiry'] || !$ready->renewalUntil) { throw new RenewalRefused(\Paymenter\Extensions\Servers\Domains\Data\RenewalCode::Grace); }
            $until = min($until, new \DateTimeImmutable($late['deadline']));
        } elseif ($late !== null) { throw new RenewalRefused(\Paymenter\Extensions\Servers\Domains\Data\RenewalCode::Grace); }
        if (!$cap || $cap['currency'] !== 'USD' || WriteMoney::compare($ready->cost->amount, (new Money($cap['amount'], 'USD'))->amount) > 0) {
            $d->update(['renewal_policy' => [...$d->renewal_policy, 'automatic_spend_paused' => true]]);
            $this->hold($op, $l, 'renewal_cost_overrun'); return null;
        }
        if ($until < $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+25 seconds')) { throw new \DomainException('Renewal admission expired.'); }
        $reservedContext->requireReservedCapacity();$claim = bin2hex(random_bytes(16)); $digest = hash('sha256', json_encode([$op->operation_key, $op->policy['renewal'], $op->payload], JSON_THROW_ON_ERROR));
        $now = $this->clock->now();
        $attempt = ['claim_key' => $claim, 'seal' => 'sealed', 'dispatch_attempted_at' => $now->format(DATE_ATOM),
            'send_state' => 'possibly_sent', 'authorized_cost' => ['amount' => $ready->cost->amount, 'currency' => 'USD'],
            'renewal_mode' => $ready->status->value];
        if ($op->funding_basis === 'paid_invoice') {
            RenewalFundingLedger::reserve($op, $funding['entries']);
            $reservation = ['renewal_reserved_revision' => $op->transition_revision + 1];
        } else {
            $reservation = ['funding' => [...($op->funding ?? []), 'claim' => $funding, 'seal' => $funding]];
        }
        $op->update([...$reservation, 'attempt' => $attempt, 'renewal_admission_until' => null, 'state' => 'running', 'started_at' => $now, 'lease_expires_at' => $now->modify('+360 seconds'),
            'request_fingerprint' => $digest]);
        $descriptor = new AdmittedOperation($binding, new Query($name, Action::Renew, (int) $op->term), $op->operation_key, $claim,
            $d->authority_generation, $claim, $digest, $ready->cost, new DateEvidence($op->base_expires_at->format('Y-m-d'), ExpiryPrecision::Date, 'renewal_base'));
        $fresh = HoldingLocks::take($d); $this->grants[$descriptor] = [
            'context' => $reservedContext, 'stamp' => $fresh['stamp'], 'id' => $id, 'revision' => $op->transition_revision,
            'mode' => $ready->status->value, 'writer' => $writer, 'binding' => $binding, 'query' => $descriptor->query,
            'claim' => $claim, 'digest' => $digest,
        ];
        return $descriptor;
    }
    /** Records the hold for a claim that never reached the seal. */
    private function refuse(array $l, int $id, int $admissionClaim, \Throwable $error, int $refusals, AdmissionDecision $first,
        ?AdmissionDecision $ready): void
    {
        $op = $l['operations']->firstWhere('id', $id);
        if ($op && RenewalDecision::awaitsAdmission($op->state) && $op->transition_revision === $admissionClaim && $op->renewal_admission_until !== null && RenewalCycles::registrarVirgin($op)) {
            $reason = $error instanceof RenewalRefused ? $error->reason : ($error instanceof ReadDeferred?RenewalCode::Rate:RenewalCode::Changed);
            $retry = in_array($reason, [RenewalCode::Rate, RenewalCode::Reads, RenewalCode::Balance, RenewalCode::Changed, RenewalCode::Acceptance], true);
            $cap=EnomTestRenewal::enabled(AccountBindings::binding($l['account']))&&($error instanceof EnomTestCallLimit||EnomTestCalls::refusedSince($refusals));
            $guard=$error instanceof WriteHeld&&$error->reason==='namesilo_account_guard';
            if($reason===RenewalCode::Balance&&($until=($ready??$first)->renewalUntil)!==null){
                $op->update(['policy'=>[...$op->policy,'funds_wait_until'=>$op->policy['funds_wait_until']??$until->format(DATE_ATOM)]]);
            }
            $pause=$error instanceof RenewalHold&&$error->reason===EnomLateRenewal::PAUSED;
            $this->hold($op, $l,$pause?EnomLateRenewal::PAUSED:($guard?'namesilo_account_guard':($cap?'enom_test_renewal_call_cap':$reason->value)),($pause||$retry)&&!$cap&&!$guard);
        }
    }
    private function dispatch($seed,int $id,AdmittedOperation $descriptor,RequestContext $context,RenewalWriter $writer): Operation
    {
        $result = null; $failure = null;
        try { $result = $writer->renew($descriptor, $context); }
        catch (\Throwable $error) {
            // Enom may already have renewed, so the reason is kept for staff, never the raw error.
            $failure = $error instanceof SendFailure ? $error : null;
            Log::warning('Domains renewal reply not recorded.', ['operation_id' => $id, 'exception_class' => $error::class] + ($failure?->facts() ?? []));
        }
        finally { unset($this->grants[$descriptor]); }
        // A deferred reply record leaves the claim running; its lease ends and completion reads the registrar.
        return $this->decide($seed, $id, function (array $l) use ($id, $descriptor, $result, $failure, $context) {
            $op = $l['operations']->firstWhere('id', $id);
            if ($op->state === 'succeeded' || ($op->attempt['claim_key'] ?? null) !== $descriptor->claimKey || array_key_exists('execution', $op->attempt)) { return $op; }
            try { $result?->binding->requireMatch($descriptor->binding); } catch (\Throwable) { $result = null; }
            $a = $op->attempt; $a['execution'] = $result?->jsonSerialize();
            if ($failure && !$result) { $a['send_failure'] = $failure->facts(); }
            $facts = $result?->evidence()->renewalExecution;
            if ($facts && $facts->query->matches($descriptor->query)) { $a['renewal'] = ['query' => ['name' => $facts->query->name, 'years' => $facts->query->years], 'order_reference' => $facts->orderReference]; }
            $unsent = $result?->sendState === SendState::DefinitelyUnsent;
            $transition = RenewalDecision::reply($op->state, $op->safe_code, $result?->sendState, $result?->disposition,
                $context->deadline > $this->clock->now());
            $op->update(['attempt' => $a, 'provider_order_id' => $facts?->orderReference, ...$transition, 'lease_expires_at' => null,
                'policy' => [...$op->policy, 'renewal_execution' => ['staff_alert' => true, 'reason' => $unsent ? 'renewal_definitely_unsent' : 'awaiting_unique_charge', 'refundable_later' => $unsent && $this->hasPayment($op, $l)]]]); return $op;
        }) ?? Operation::findOrFail($id);
    }
    /** One locked step through the money runner. Contention twice changes nothing and queues the renewal; other errors throw. */
    private function decide($seed, int $id, \Closure $step): mixed
    {
        $decision = MoneyDecisions::run(new MoneyRequest($seed, self::KIND, $id), $step);
        if ($decision->error) { throw $decision->error; }
        return $decision->value;
    }
    /** Exact-object consume at the last local boundary, after final reads and before the single wire call.
     * The grant is gone before its check runs. Returns the sealed command: send mode, query and account binding.
     * @return array{string, Query, AccountBinding} */
    public function consume(AdmittedOperation $op, RequestContext $context, RenewalWriter $writer): array
    {
        OperationClaims::outside(); $grant = $this->grants[$op] ?? null; unset($this->grants[$op]);
        if (!$grant || $grant['context'] !== $context || $grant['writer'] !== $writer) { throw new \DomainException('No live renewal grant.'); }
        try { FreshTransaction::run(function () use ($context, $grant, $writer) {
            $row = Operation::findOrFail($grant['id']); $l = HoldingLocks::take($row->domain()->firstOrFail()); $row = $l['operations']->firstWhere('id', $row->id);
            $funding = $this->standing->current($row, $l); $writer->requirePolicy($grant['binding'], $this->features, $this->accepted);
            $matchesFunding = $row->funding_basis === 'paid_invoice'
                ? RenewalFundingLedger::entries($row) === $funding['entries']
                : $row->funding['seal'] === $funding;
            $attempt = $row->attempt;
            $reservationMatches = $l['stamp'] === $grant['stamp'] && ($attempt['seal'] ?? null) === 'sealed'
                && ($attempt['claim_key'] ?? null) === $grant['claim'] && $row->request_fingerprint === $grant['digest'];
            if (!RenewalDecision::canSend($grant['revision'], $row->transition_revision, $row->state,
                $reservationMatches, $matchesFunding, $row->lease_expires_at, $context->deadline, $this->clock->now())) {
                throw new \DomainException('Sealed renewal authority changed.');
            }
        }); } catch (\Throwable $error) { throw new RenewalUnsent('Live renewal refused before the wire.', previous: $error); }
        return [$grant['mode'], $grant['query'], $grant['binding']];
    }
    private function hasPayment(Operation $op, array $l): bool
    {
        return $op->funding_basis === 'paid_invoice' && array_sum(RenewalFunding::payments($op, $l)) > 0;
    }
    private function hold(Operation $op, array $l, string $reason, bool $retry = false, array $facts = []): void
    {
        if($reason===RenewalCode::Balance->value&&\Illuminate\Support\Facades\Schema::hasTable('domains_obligation_alerts')){ObligationAlerts::record($op->domain,'renewal_funds',$this->clock->now()->getTimestamp(),(int)$op->id);}
        $hold = RenewalDecision::hold($reason, $retry, !$retry && $this->hasPayment($op, $l));
        $op->update(['renewal_admission_until' => null, 'state' => $hold['state'], 'safe_code' => $hold['safe_code'],
            'policy' => [...$op->policy, 'renewal_execution' => [...$hold['renewal_execution'], ...$facts]]]);
    }
}
