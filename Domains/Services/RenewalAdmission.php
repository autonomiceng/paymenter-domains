<?php
// Adapted from Enom 444f31c RenewalAdmission.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Action, Disposition, ExpiryPrecision, Query, RenewalCode, RenewalDecision, RenewalRequest, RenewalStatus, RequestBudget, RequestContext, RequestOrigin, SendState};
/** Prospective admission only: no obligation, invoice, claim, seal, funding grant or provider write. */
final readonly class RenewalAdmission
{
    public const MAX_READS = 10;
    public const READ_SECONDS = 250;
    public function __construct(private RenewalAuthority $authority, private AccountBindings $bindings,
        private RenewalAcceptance $accepted, private Clock $clock) {}
    public function check(RenewalRequest $request, ?\DateTimeImmutable $finishCap = null): RenewalDecision
    {
        return $this->entered($request, true, $finishCap);
    }
    /** Final executor refresh carries the original reservation; no transferable grant is returned. */
    public function refresh(RenewalRequest $request, ?\DateTimeImmutable $finishCap = null): RenewalDecision
    { return $this->entered($request, false, $finishCap); }
    private function entered(RenewalRequest $request, bool $reserve, ?\DateTimeImmutable $finishCap): RenewalDecision
    {
        return $finishCap !== null ? $this->assess($request, $reserve, $finishCap)
            : EntryDeadline::run($this->clock, fn ($deadline) => $this->assess($request, $reserve, $deadline), self::READ_SECONDS);
    }
    private function assess(RenewalRequest $request, bool $reserve, \DateTimeImmutable $finishCap): RenewalDecision
    {
        OperationClaims::outside(); $status = RenewalStatus::Unknown;
        try {
            [$binding, $name, $stamp, $retail, $nonpremium] = $this->authority->capture($request);
            if(EnomTestRenewal::enabled($binding)&&$request->years!==1){throw new RenewalRefused(RenewalCode::Term);}
            if ($reserve) { $this->accepted->reserve($binding); } else { $this->accepted->requireCurrent($binding); }
            $reader = $this->bindings->reader($binding->serverId); $binding->requireMatch($reader->binding());
            $started = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            $context = new RequestContext($binding, RequestOrigin::Background, min($finishCap, $started->modify('+'.self::READ_SECONDS.' seconds')), new RequestBudget(self::MAX_READS), $this->clock, Runtime::limiter());
            $query = new Query($name, Action::Renew, $request->years);
            $assessment = $reader->renewalAssessment($query, $context); $binding->requireMatch($assessment->binding);
            $facts = $assessment->evidence(); $status = $facts->renewal;
            if ($assessment->disposition !== Disposition::Accepted && $assessment->sendState === SendState::PossiblySent) { throw new RenewalRefused(RenewalCode::Reads); }
            // Provider state alone never establishes local authority or a safe restoration path.
            if (in_array($status, [RenewalStatus::Redemption, RenewalStatus::ExtendedRedemption], true)) { throw new RenewalRefused(RenewalCode::Restore); }
            if ($status === RenewalStatus::Ineligible) { throw new RenewalRefused(RenewalCode::Term); }
            if ($assessment->disposition !== Disposition::Accepted || !in_array($status, [RenewalStatus::Ordinary, RenewalStatus::Grace], true)) {
                throw new RenewalRefused(RenewalCode::State);
            }
            if ($status === RenewalStatus::Grace && (!$this->accepted->grace || $request->years !== 1)) {
                throw new RenewalRefused($request->years !== 1 ? RenewalCode::Term : RenewalCode::Grace);
            }
            $lifecycleUntil = null;
            if ($status === RenewalStatus::Grace) {
                $ending = explode('.', $name, 2)[1];
                $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
                $policy = LifecyclePolicies::read($binding, $ending);
                if ($policy === null || !LifecycleFacts::allows($policy, 'grace', $now)) { throw new RenewalRefused(RenewalCode::Grace); }
                $expiry = $facts->domain?->expiry;
                if ($expiry?->precision !== ExpiryPrecision::Instant) { throw new RenewalRefused(RenewalCode::Grace); }
                // DateEvidence validates the instant; the request date matches the locked local anchor.
                $providerExpiry = (new \DateTimeImmutable($expiry->value))->setTimezone(new \DateTimeZone('UTC'));
                $localExpiry = new \DateTimeImmutable($request->expiry, new \DateTimeZone('UTC'));
                $graceDeadline = min($providerExpiry, $localExpiry)->modify('+'.$policy['grace_days'].' days');
                if ($now < $providerExpiry) { throw new RenewalRefused(RenewalCode::Grace); }
                if ($now >= $graceDeadline) { throw new RenewalRefused(RenewalCode::Grace); }
                $lifecycleUntil = $graceDeadline;
            }
            $this->owned($facts->domain, $request, $binding, $name);
            if ($binding->registrarKey === 'namesilo' ? !$nonpremium : $facts->premium !== false) { throw new RenewalRefused(RenewalCode::Cost); }
            $cost = WriteMoney::cost($facts->quote, $query, $started, $this->clock->now());
            if (!$cost) { throw new RenewalRefused(RenewalCode::Cost); }
            $flags = $reader->readRenewalDomain($name, $context); $binding->requireMatch($flags->binding);
            if ($flags->disposition !== Disposition::Accepted) { throw new RenewalRefused($flags->sendState === SendState::PossiblySent ? RenewalCode::Reads : RenewalCode::Owner); }
            $this->owned($flags->evidence()->domain, $request, $binding, $name);
            if ($binding->registrarKey === 'namesilo' && $flags->evidence()->domain->active !== true) { throw new RenewalRefused(RenewalCode::State); }
            if ($flags->evidence()->domain->expiry != $facts->domain->expiry) { throw new RenewalRefused(RenewalCode::Anchor); }
            if ($flags->evidence()->domain->providerAutoRenew !== false) { throw new RenewalRefused(RenewalCode::AutoRenew); }
            $balance = BalanceReads::read($reader, $context, 'renewal'); $binding->requireMatch($balance->binding);
            if ($balance->disposition !== Disposition::Accepted || !WriteMoney::sufficient($balance->evidence()->balance, $cost)) {
                throw new RenewalRefused(RenewalCode::Balance);
            }
            $until = min($context->deadline, $facts->quote->validUntil, $started->modify('+120 seconds'));
            if ($status === RenewalStatus::Ordinary && $facts->domain->expiry->precision === ExpiryPrecision::Instant) {
                $until = min($until, new \DateTimeImmutable($facts->domain->expiry->value));
            }
            if ($lifecycleUntil !== null) { $until = min($until, $lifecycleUntil); }
            $this->authority->recheck($request, $stamp, $this->accepted, $until);
            return new RenewalDecision(RenewalCode::Admitted, $status, $cost, $until,
                $retail !== null && WriteMoney::compare($cost->amount, $retail) > 0 ? 'below_cost' : null);
        } catch (RenewalRefused $error) { return new RenewalDecision($error->reason, $status); }
        catch (\Throwable) { return new RenewalDecision(RenewalCode::Reads, $status); }
    }
    private function owned($snapshot, RenewalRequest $request, $binding, string $name): void
    {
        if (!$snapshot || $snapshot->binding->fingerprint !== $binding->fingerprint || $snapshot->owned !== true
            || $snapshot->name !== $name
            || ($binding->registrarKey === 'namesilo'
                ? $snapshot->providerReference !== null || $request->providerId !== null
                : $snapshot->providerReference !== $request->providerId)) { throw new RenewalRefused(RenewalCode::Owner); }
        // Slice22 stores the normalized evidence calendar date without inventing a new timezone.
        $day = $snapshot->expiry ? substr($snapshot->expiry->value, 0, 10) : null;
        if ($day !== $request->expiry) { throw new RenewalRefused(RenewalCode::Anchor); }
    }
}
