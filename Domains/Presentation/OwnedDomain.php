<?php
// Enom 444f31c owner/service presentation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/**
 * Customer data only. No models, binding, operation keys, provider IDs or financial evidence.
 * tone: ok|info|warn|bad|muted; status is the customer-facing label.
 * expiry: registrar-reported registration expiry (Y-m-d), null when unconfirmed.
 * renewalDue: due date (Y-m-d) of the exact retained pending renewal invoice, not expiry
 * or a projected renewal date; null when no matching issued invoice is confirmed.
 * renewal: On or Off for an active domain's local renewal preference; otherwise —,
 * or Paused during setup. This does not describe the registrar's auto-renew setting.
 * renewalOff: cancellation|staff|adopted|owner, in that precedence; null when renewal is On.
 * autoRenew: owner switch url and enabled boolean, or empty when unavailable.
 * plannedRenewalDue: projected future due date for an adopted domain, separate from renewalDue.
 * renewNow: eligible owner renewal request: url, terms and last request date
 * (order_by, Y-m-d), or only invoice for this cycle's issued pending invoice, or only status
 * (cancelled, paid or review) when this cycle can be neither requested nor paid, independent of new-request switches; otherwise empty.
 */
final readonly class OwnedDomain
{
    public function __construct(public string $name, public string $status, public string $tone,
        public ?string $expiry, public string $renewal, public ?string $renewalPrice,
        public array $actions, public array $progress, public array $setup, public array $transfer,
        public array $management, public bool $testEnvironment, public bool $changesUnavailable = false, public ?string $renewalDue = null, public bool $setupPending = false, public ?string $registrationBadge = null, public bool $adopted = false, public ?string $plannedRenewalDue = null, public array $history = [], public array $renewalInvoice = [], public bool $originalDoNotRenew = false, public array $renewNow = [], public ?string $renewalOff = null, public array $autoRenew = []) {}
}
