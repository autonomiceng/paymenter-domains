<?php
// Adapted from Enom 444f31c RenewalAdmission.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Action, Disposition, ExpiryPrecision, Query, RenewalCode, RenewalDecision, RenewalRequest, RenewalStatus, RequestBudget, RequestContext, RequestOrigin};
/** Prospective admission only: no obligation, invoice, claim, seal, funding grant or provider write. */
final readonly class RenewalAdmission
{
    public const MAX_READS = 10;
    public const READ_SECONDS = 250;
    public function __construct(private RenewalAuthority $authority, private AccountBindings $bindings,
        private RenewalAcceptance $accepted, private Clock $clock) {}
    public function check(RenewalRequest $request): RenewalDecision
    {
        OperationClaims::outside(); $status = RenewalStatus::Unknown;
        try {
            [$binding, $name, $stamp, $retail] = $this->authority->capture($request);
            $this->accepted->reserve($binding);
            $reader = $this->bindings->reader($binding->serverId); $binding->requireMatch($reader->binding());
            $started = $this->clock->now();
            $context = new RequestContext($binding, RequestOrigin::Background, $started->modify('+'.self::READ_SECONDS.' seconds'), new RequestBudget(self::MAX_READS), $this->clock);
            $query = new Query($name, Action::Renew, $request->years);
            $assessment = $reader->renewalAssessment($query, $context); $binding->requireMatch($assessment->binding);
            $facts = $assessment->evidence(); $status = $facts->renewal;
            // Provider state alone never establishes local authority or a safe restoration path.
            if ($status === RenewalStatus::Redemption) { throw new RenewalRefused(RenewalCode::Restore); }
            if ($status === RenewalStatus::Ineligible) { throw new RenewalRefused(RenewalCode::Term); }
            if ($assessment->disposition !== Disposition::Accepted || !in_array($status, [RenewalStatus::Ordinary, RenewalStatus::Grace], true)) {
                throw new RenewalRefused(RenewalCode::State);
            }
            if ($status === RenewalStatus::Grace && (!$this->accepted->grace || $request->years !== 1)) {
                throw new RenewalRefused($request->years !== 1 ? RenewalCode::Term : RenewalCode::Grace);
            }
            $this->owned($facts->domain, $request, $binding, $name);
            if ($facts->premium !== false) { throw new RenewalRefused(RenewalCode::Cost); }
            $cost = WriteMoney::cost($facts->quote, $query, $started, $this->clock->now());
            if (!$cost) { throw new RenewalRefused(RenewalCode::Cost); }
            $flags = $reader->readDomain($name, $context); $binding->requireMatch($flags->binding);
            if ($flags->disposition !== Disposition::Accepted) { throw new RenewalRefused(RenewalCode::Owner); }
            $this->owned($flags->evidence()->domain, $request, $binding, $name);
            if ($flags->evidence()->domain->expiry != $facts->domain->expiry) { throw new RenewalRefused(RenewalCode::Anchor); }
            if ($flags->evidence()->domain->providerAutoRenew !== false) { throw new RenewalRefused(RenewalCode::AutoRenew); }
            $balance = $reader->balance($context); $binding->requireMatch($balance->binding);
            if ($balance->disposition !== Disposition::Accepted || !WriteMoney::sufficient($balance->evidence()->balance, $cost)) {
                throw new RenewalRefused(RenewalCode::Balance);
            }
            $until = min($context->deadline, $facts->quote->validUntil, $started->modify('+120 seconds'));
            if ($status === RenewalStatus::Ordinary && $facts->domain->expiry->precision === ExpiryPrecision::Instant) {
                $until = min($until, new \DateTimeImmutable($facts->domain->expiry->value));
            }
            $this->authority->recheck($request, $stamp, $this->accepted, $until);
            return new RenewalDecision(RenewalCode::Admitted, $status, $cost, $until,
                $retail !== null && WriteMoney::compare($cost->amount, $retail) > 0 ? 'below_cost' : null);
        } catch (RenewalRefused $error) { return new RenewalDecision($error->reason, $status); }
        catch (\Throwable) { return new RenewalDecision(RenewalCode::Reads, $status); }
    }
    private function owned($snapshot, RenewalRequest $request, $binding, string $name): void
    {
        if (!$snapshot || $snapshot->binding->fingerprint !== $binding->fingerprint || $snapshot->owned !== true
            || $snapshot->name !== $name || $snapshot->providerReference !== $request->providerId) { throw new RenewalRefused(RenewalCode::Owner); }
        // Slice22 stores the normalized evidence calendar date without inventing a new timezone.
        $day = $snapshot->expiry ? substr($snapshot->expiry->value, 0, 10) : null;
        if ($day !== $request->expiry) { throw new RenewalRefused(RenewalCode::Anchor); }
    }
}
