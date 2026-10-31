<?php
// Enom 444f31c owner/service presentation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/**
 * Customer data only. No models, binding, operation keys, provider IDs or financial evidence.
 * status, tone, statusReason: the DomainStatus label, client tone and reason line (see StatusPill).
 * expiry: registrar-reported registration expiry (Y-m-d), null when unconfirmed.
 * renewalDue: due date (Y-m-d) of the exact retained pending renewal invoice, not expiry
 * or a projected renewal date; null when no matching issued invoice is confirmed.
 * renewal: On or Off for an active domain's local renewal preference; otherwise empty. This does not describe the registrar's auto-renew setting.
 * renewalOff: cancellation|staff|adopted|owner, in that precedence; null when renewal is On.
 * autoRenew: owner switch url and enabled boolean, or empty when unavailable.
 * daysLeft: whole days from today (the service clock, UTC) to expiry; null without an expiry.
 * syncPaused: a demo domain whose demo registrar is off while a real test registrar account is in use; its synced values are Missing.
 * testEnvironment: registered through a registrar account in test mode. demo: seeded demo data (DemoData::is); views show Demo over Test.
 * plannedRenewalDue: projected future due date for an adopted domain, separate from renewalDue.
 * owner: the registrant's name and email as last stored (a confirmed contact change, else the registration request); null when none is on file or for list rows.
 * order: a pending order's payment (OwnedDomains::order): state unpaid, paid or cancelled, kind registration or transfer, invoice url when unpaid; empty otherwise.
 * autoPay: this service's saved payment method can be charged, so a scheduled renewal invoice is charged to it.
 * renewNow: eligible owner renewal request: url, terms and last request date
 * (order_by, Y-m-d), or only invoice for this cycle's issued pending invoice, or only status
 * (cancelled, paid or review) when this cycle can be neither requested nor paid, independent of new-request switches; otherwise empty.
 */
final readonly class OwnedDomain
{
    public function __construct(public string $name, public string $status, public string $tone,
        public ?string $expiry, public string $renewal, public ?string $renewalPrice,
        public array $actions, public array $progress, public array $setup, public array $transfer,
        public array $management, public bool $testEnvironment, public bool $changesUnavailable = false, public ?string $renewalDue = null, public bool $setupPending = false, public bool $adopted = false, public ?string $plannedRenewalDue = null, public array $history = [], public array $renewalInvoice = [], public bool $originalDoNotRenew = false, public array $renewNow = [], public ?string $renewalOff = null, public array $autoRenew = [], public ?string $statusReason = null, public bool $demo = false, public bool $syncPaused = false, public ?int $daysLeft = null, public ?array $owner = null, public array $order = [], public bool $autoPay = false, public bool $earlyRenewal = false) {}
}
