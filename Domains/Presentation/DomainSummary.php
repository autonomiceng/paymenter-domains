<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Theme-neutral customer facts only. All URLs are checked against current native ownership.
 * testEnvironment: owned managed registration test flag only; watches are not registrar accounts.
 * demo: seeded demo data (DemoData::is), for owned domains and hosting watches.
 * statusState/statusReason: the DomainStatus state and reason from DomainStates; null state shows no pill.
 * daysLeft: whole days from today to the expiry date. renewalInvoiceMissing: auto-renew is on and the renewal invoice
 * should exist by now but does not. expiryWaits: what the missing expiry waits for (import, registration or transfer).
 * renewalIssueAt: the calendar issue date of that missing invoice (Y-m-d), when it was projected. */
final readonly class DomainSummary
{
    public function __construct(public string $name,public ?string $displayName,public string $kind,
        public string $registrationKey,public array $dnsKeys,public ?bool $website,public ?bool $email,
        public array $stateKeys,public array $labels,public ?string $expiry,public ?string $expiryPrecision,
        public ?string $expirySource,public array $freshness,public ?string $renewalDueAt,public ?string $renewalPrice,
        public array $relatedServices,public array $hostnames,public array $urls,public bool $renewalOff=false,public array $lastKnown=[],public int $relatedOverflow=0,public array $noticeKeys=[],public array $noticeDates=[],public bool $renewalReminderOff=false,public bool $renewalDueProjected=false,public bool $testEnvironment=false,public ?array $nameservers=null,public ?string $statusState=null,public ?string $statusReason=null,public bool $demo=false,public ?int $daysLeft=null,public bool $renewalInvoiceMissing=false,public ?string $expiryWaits=null,public ?string $renewalIssueAt=null) {}
    /** The index Renewal column says "Auto-renew off" exactly when this is true. */
    public function renewalOffShown(): bool { return $this->renewalOff && !in_array('adopt.pending',$this->stateKeys,true); }
}
