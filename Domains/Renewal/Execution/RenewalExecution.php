<?php
// Enom d1392a8 managed renewal execution; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Renewal\Execution;
use Paymenter\Extensions\Servers\Domains\Money\Allocations\{RenewalFundingLedger, RenewalReservation};
use Paymenter\Extensions\Servers\Domains\Money\Native\{MoneyDecisions, MoneyLockContext};
use Illuminate\Support\Facades\{DB, Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AdmittedOperation, DateEvidence, ExpiryPrecision, Money, Query, RenewalCode, RenewalDecision as AdmissionDecision, RenewalRequest, RequestBudget, RequestContext, RequestOrigin, SendFailure, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptToken, OperationAttempts, OperationWriter, SealTerms, StepKey, Transition, WireOutcome};
use Paymenter\Extensions\Servers\Domains\Registrars\Dispatch\{RenewalUnsent, RenewalWriter};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, ComplimentaryCosts, EnomLateRenewal, EnomTestCallLimit, EnomTestCalls, EnomTestRenewal, EnomTestWriteReservation, EntryDeadline, FreshTransaction, FundsWaits, HoldingLocks, LateRenewalQuote, MoneyRequest, ObligationAlerts, OperationClaims, ReadDeferred, RenewalAcceptance, RenewalAuthority, RenewalBusy, RenewalCycles, RenewalFunding, RenewalHold, RenewalRefused, Runtime, WriteFeatures, WriteHeld, WriteMoney};
/** One sealed attempt. A durable row, request or admission decision is never a live grant. */
final class RenewalExecution
{
    /** Renewal steps deferred by database contention. The runtime turn hands them back to the operation queue. */
    public const KIND = 'renewal';
    /** Live grants, one per sealed request object, minted only by the seal transition. */
    private \WeakMap $grants;
    private readonly RenewalStanding $standing;
    private readonly OperationWriter $attempts;
    public function __construct(private readonly AccountBindings $bindings, private readonly RenewalAuthority $authority,
        private readonly RenewalAdmission $admission, private readonly RenewalAcceptance $accepted,
        private readonly WriteFeatures $features, private readonly Clock $clock)
    { $this->standing = new RenewalStanding($bindings, $accepted, $features, $clock); $this->attempts = new OperationWriter($clock); $this->grants = new \WeakMap; }
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
        $capture = $this->decide($seed, $id, fn (MoneyLockContext $locks) => $this->admit($locks, $id));
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
            $this->decide($seed, $id, fn (MoneyLockContext $locks) => $this->refuse($locks, $id, $admissionClaim, $error, $refusals, $first, $ready));
        }
        return Operation::findOrFail($id);
    }
    /** Admission under the holding locks: a claim revision and the renewal request, or null after any hold. */
    private function admit(MoneyLockContext $locks, int $id): ?array
    {
        $op = $locks->operation($id);
        if ($op?->action !== 'renew') { return null; }
        $l = $locks->facts();
        $claimed = OperationAttempts::current((int) $op->id, StepKey::renew(), true);
        $next = RenewalDecision::admission($op->state, $op->lease_expires_at, $claimed?->claiming($this->clock->now()) ? $claimed->claimUntil : null, $this->clock->now());
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
        $token = $this->attempts->claim((int) $op->id, StepKey::renew(), $this->clock->now()->modify('+360 seconds'));
        if (!$token instanceof AttemptToken) { return null; }
        // The claim is also an operation transition, so a worker holding an older revision cannot seal.
        $claim = $op->transition_revision + 1; $op->update(['transition_revision' => $claim]);
        $d = $l['domain']; return [new RenewalRequest($d->id, $d->authority_generation, $d->confirmed_revision,
            $d->provider_expires_at->format('Y-m-d'), $d->provider_domain_id, (int) $op->term, $op->operation_key), [$claim, $token]];
    }
    /** Seals the attempt under the holding locks and issues its one live grant. Null means a local hold was recorded. */
    private function seal(array $l, int $id, AccountBinding $binding, $name, $stamp, AdmissionDecision $ready, \DateTimeInterface $until,
        RenewalWriter $writer, array $admissionClaim, RequestContext $reservedContext): ?AdmittedOperation
    {
        $op = $l['operations']->firstWhere('id', $id); $d = $l['domain']; [$revision, $token] = $admissionClaim;
        if ($l['stamp'] !== $stamp || !$op || $op->transition_revision !== $revision
            || !OperationAttempts::attempt($token, true)?->claiming($this->clock->now()) || !RenewalDecision::awaitsAdmission($op->state) || !RenewalCycles::registrarVirgin($op)) { throw new \DomainException('Renewal changed.'); }
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
        $reservedContext->requireReservedCapacity();$claim = $token->claimKey; $digest = hash('sha256', json_encode([$op->operation_key, $op->policy['renewal'], $op->payload], JSON_THROW_ON_ERROR));
        $now = $this->clock->now();
        // The sealed attempt records the post-seal revision; for a paid renewal it identifies the reserved postings.
        if ($funding instanceof RenewalReservation) { RenewalFundingLedger::reserve($funding); $reservation = []; }
        else { $reservation = ['funding' => [...($op->funding ?? []), 'claim' => $funding, 'seal' => $funding]]; }
        // The seal is the attempt row; the operation keeps its run state, start and request digest.
        $op->update([...$reservation, 'state' => 'running', 'started_at' => $now, 'lease_expires_at' => $now->modify('+360 seconds'),
            'request_fingerprint' => $digest]);
        if ($this->attempts->seal($token, new SealTerms($op->transition_revision, $digest, $ready->cost, $ready->status->value, $now->modify('+360 seconds'))) !== Transition::Applied) {
            throw new \DomainException('Renewal claim changed.');
        }
        $descriptor = new AdmittedOperation($binding, new Query($name, Action::Renew, (int) $op->term), $op->operation_key, $claim,
            $d->authority_generation, $claim, $digest, $ready->cost, new DateEvidence($op->base_expires_at->format('Y-m-d'), ExpiryPrecision::Date, 'renewal_base'));
        $this->grants[$descriptor] = [
            'context' => $reservedContext, 'stamp' => $l['stampForHeldRows'](), 'id' => $id, 'revision' => $op->transition_revision,
            'mode' => $ready->status->value, 'writer' => $writer, 'binding' => $binding, 'query' => $descriptor->query,
            'digest' => $digest, 'attempt' => $token,
        ];
        return $descriptor;
    }
    /** Records the hold for a claim that never reached the seal. */
    private function refuse(MoneyLockContext $locks, int $id, array $admissionClaim, \Throwable $error, int $refusals, AdmissionDecision $first,
        ?AdmissionDecision $ready): void
    {
        $op = $locks->operation($id); [$revision, $token] = $admissionClaim; $claim = OperationAttempts::attempt($token, true);
        if ($op && RenewalDecision::awaitsAdmission($op->state) && $op->transition_revision === $revision && $claim?->current && !$claim->sealed() && !$claim->released && RenewalCycles::registrarVirgin($op)) {
            $l = $locks->facts();
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
        $result = null; $failure = null; $token = $this->grants[$descriptor]['attempt'] ?? null;
        try { $result = $writer->renew($descriptor, $context); }
        catch (\Throwable $error) {
            // Enom may already have renewed, so the reason is kept for staff, never the raw error.
            $failure = $error instanceof SendFailure ? $error : null;
            Log::warning('Domains renewal reply not recorded.', ['operation_id' => $id, 'exception_class' => $error::class] + ($failure?->facts() ?? []));
        }
        finally { unset($this->grants[$descriptor]); }
        // A deferred reply record leaves the claim running; its lease ends and completion reads the registrar.
        return $this->decide($seed, $id, function (MoneyLockContext $locks) use ($id, $descriptor, $token, $result, $failure, $context) {
            $op = $locks->operation($id);
            if (!$token instanceof AttemptToken || $op->state === 'succeeded') { return $op; }
            try { $result?->binding->requireMatch($descriptor->binding); } catch (\Throwable) { $result = null; }
            // The reply belongs to the exact attempt this grant sealed. A replaced attempt or an earlier reply projects nothing.
            if ($this->attempts->recordReply($token, self::outcome($result, $failure, $descriptor)) !== Transition::Applied) { return $op; }
            $facts = $result?->evidence()->renewalExecution;
            $unsent = $result?->sendState === SendState::DefinitelyUnsent;
            $transition = RenewalDecision::reply($op->state, $op->safe_code, $result?->sendState, $result?->disposition,
                $context->deadline > $this->clock->now());
            // The recorded reply advances the renewal's revision once, whether or not its state changes.
            $op->update(['transition_revision' => $op->transition_revision + 1, 'provider_order_id' => $facts?->orderReference, ...$transition, 'lease_expires_at' => null,
                'policy' => [...$op->policy, 'renewal_execution' => ['staff_alert' => true, 'reason' => $unsent ? 'renewal_definitely_unsent' : 'awaiting_unique_charge', 'refundable_later' => $unsent && $this->hasPayment($op, $locks->facts())]]]); return $op;
        }) ?? Operation::findOrFail($id);
    }
    /** One locked step through the money runner. Contention twice changes nothing and queues the renewal; other errors throw. */
    private function decide($seed, int $id, \Closure $step): mixed
    {
        $decision = MoneyDecisions::run(new MoneyRequest($seed, self::KIND, $id), $step);
        if ($decision->error) { throw $decision->error; }
        return $decision->value;
    }
    /** The live grant's sealed command: send mode, query and account binding. Reading it spends nothing.
     * @return array{string, Query, AccountBinding} */
    public function command(AdmittedOperation $op, RequestContext $context, RenewalWriter $writer): array
    {
        $grant = $this->grants[$op] ?? null;
        if (!$grant || $grant['context'] !== $context || $grant['writer'] !== $writer) { throw new \DomainException('No live renewal grant.'); }
        return [$grant['mode'], $grant['query'], $grant['binding']];
    }
    /** Exact-object consume in the final reserved transport callback, just before the single wire exchange.
     * The grant is gone before its fresh locked check runs; a refusal here proves the send stopped before the wire. */
    public function consume(AdmittedOperation $op, RequestContext $context, RenewalWriter $writer): void
    {
        OperationClaims::outside(); $grant = $this->grants[$op] ?? null; unset($this->grants[$op]);
        if (!$grant || $grant['context'] !== $context || $grant['writer'] !== $writer) { throw new \DomainException('No live renewal grant.'); }
        try { FreshTransaction::run(function () use ($context, $grant, $writer) {
            $row = Operation::findOrFail($grant['id']); $l = HoldingLocks::take($row->domain()->firstOrFail()); $row = $l['operations']->firstWhere('id', $row->id);
            $funding = $this->standing->current($row, $l); $writer->requirePolicy($grant['binding'], $this->features, $this->accepted);
            $matchesFunding = $funding instanceof RenewalReservation
                ? RenewalFundingLedger::reservation($row) == $funding
                : $row->funding['seal'] === $funding;
            // The sealed attempt row, not the JSON copy, carries the grant's claim, digest and revision.
            $attempt = OperationAttempts::attempt($grant['attempt'], true);
            $reservationMatches = $l['stamp'] === $grant['stamp'] && $attempt?->current && $attempt->sealed()
                && $attempt->requestDigest === $grant['digest'] && $attempt->operationRevision === $grant['revision'];
            if (!RenewalDecision::canSend($grant['revision'], $row->transition_revision, $row->state,
                $reservationMatches, $matchesFunding, $row->lease_expires_at, $context->deadline, $this->clock->now())
                || $this->attempts->consumeGrant($grant['attempt']) !== Transition::Applied) {
                throw new \DomainException('Sealed renewal authority changed.');
            }
        }); } catch (\Throwable $error) { throw new RenewalUnsent('Live renewal refused before the wire.', previous: $error); }
    }
    private function hasPayment(Operation $op, array $l): bool
    {
        return $op->funding_basis === 'paid_invoice' && array_sum(RenewalFunding::payments($op, $l)) > 0;
    }
    private function hold(Operation $op, array $l, string $reason, bool $retry = false, array $facts = []): void
    {
        if($reason===RenewalCode::Balance->value&&\Illuminate\Support\Facades\Schema::hasTable('domains_obligation_alerts')){ObligationAlerts::record($op->domain,'renewal_funds',$this->clock->now()->getTimestamp(),(int)$op->id);}
        $hold = RenewalDecision::hold($reason, $retry, !$retry && $this->hasPayment($op, $l));
        // Ending a live pre-seal claim is itself a transition.
        $released = ($claim = OperationAttempts::current((int) $op->id, StepKey::renew(), true)) && $this->attempts->releasePreSeal($claim->token) === Transition::Applied;
        $op->update([...($released ? ['transition_revision' => $op->transition_revision + 1] : []), 'state' => $hold['state'], 'safe_code' => $hold['safe_code'],
            'policy' => [...$op->policy, 'renewal_execution' => [...$hold['renewal_execution'], ...$facts]]]);
    }
    /** The sealed attempt's wire outcome. Only the typed local refusal proves no send; no reply stays unknown. */
    /** The acceptance is kept only for the sealed query, so its order reference binds this attempt. */
    private static function outcome(?\Paymenter\Extensions\Servers\Domains\Data\DriverResult $result, ?SendFailure $failure, AdmittedOperation $descriptor): WireOutcome
    {
        if (!$result) { return WireOutcome::unknown($failure ? ['send_failure' => $failure->facts()] : []); }
        if ($result->sendState === SendState::DefinitelyUnsent) { return WireOutcome::localRefusal($result->reason->value, ['result' => $result->jsonSerialize()]); }
        $facts = $result->evidence()->renewalExecution;
        $acceptance = $facts && $facts->query->matches($descriptor->query)
            ? ['query' => ['name' => $facts->query->name, 'years' => $facts->query->years], 'order_reference' => $facts->orderReference] : null;
        return WireOutcome::reply($result->sendState, $result->disposition->value, ['result' => $result->jsonSerialize(), 'acceptance' => $acceptance]);
    }
}
