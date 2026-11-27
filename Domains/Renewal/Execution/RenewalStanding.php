<?php
// Enom d1392a8 managed renewal execution; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Renewal\Execution;
use Paymenter\Extensions\Servers\Domains\Money\Allocations\RenewalReservation;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Action, RenewalCode};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomLateRenewal, InvoiceMoney, LateRenewalQuote, RenewalAcceptance, RenewalFunding, RenewalHold, RenewalRefused, WriteFeatures};
/** Current settings, spend pause and funding of one renewal under its holding locks. Returns the funding facts or throws. */
final class RenewalStanding
{
    public function __construct(private readonly AccountBindings $bindings, private readonly RenewalAcceptance $accepted,
        private readonly WriteFeatures $features, private readonly Clock $clock) {}
    public function current(Operation $op, array $l): RenewalReservation|array
    {
        $this->configured($l);
        if (($deadline=LateRenewalQuote::deadline($op)) !== null) {
            if ($deadline <= $this->clock->now()->modify('+25 seconds')) { throw new RenewalRefused(RenewalCode::Grace); }
            if (!EnomLateRenewal::accepted(AccountBindings::binding($l['account']))) { throw new RenewalHold(EnomLateRenewal::PAUSED, 'Late renewal Off for this account.'); }
        }
        if ($l['domain']->renewal_policy['automatic_spend_paused'] ?? false) { throw new RenewalHold('renewal_spend_paused', 'Renewal spending paused.'); }
        if ($op->expected_expires_at > $this->clock->now()->modify('+10 years')) { throw new \DomainException('Renewal term too long.'); }
        $funding = RenewalFunding::requireCurrent($op, $l);
        if ($funding instanceof RenewalReservation) {
            // A gateway refund can reduce available money without changing any reserved native posting.
            $peers = Operation::where('invoice_id', $op->invoice_id)->orderBy('id')->lockForUpdate()->get();
            $money = InvoiceMoney::lockedRecord($l, $op->invoice_id, $peers);
            RenewalDecision::requireFunding($funding->invoiceTotalMinor(), InvoiceMoney::held($money));
        }
        return $funding;
    }
    private function configured(array $l): void
    {
        try {
            $this->features->requireEnabled(Action::Renew); $this->bindings->requireProcessingLocked($l['server'], $l['account']);
            $this->accepted->requireCurrent(AccountBindings::binding($l['account']));
        } catch (\PDOException $e) { throw $e; } catch (\Throwable $error) { throw new RenewalRefused(RenewalCode::Acceptance); }
    }
}
