<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Theme-neutral customer facts only. All URLs are checked against current native ownership.
 * testEnvironment: owned managed registration test flag only; watches are not registrar accounts. */
final readonly class DomainSummary
{
    public function __construct(public string $name,public ?string $displayName,public string $kind,
        public string $registrationKey,public array $dnsKeys,public ?bool $website,public ?bool $email,
        public array $stateKeys,public array $labels,public ?string $expiry,public ?string $expiryPrecision,
        public ?string $expirySource,public array $freshness,public ?string $renewalDueAt,public ?string $renewalPrice,
        public array $relatedServices,public array $hostnames,public array $urls,public bool $renewalOff=false,public array $lastKnown=[],public int $relatedOverflow=0,public array $noticeKeys=[],public array $noticeDates=[],public bool $renewalReminderOff=false,public bool $renewalDueProjected=false,public bool $testEnvironment=false) {}
    public function tone(string $key): string { return DomainSummaryTones::for($key); }
    /** The index Renewal column says "Renewal off" exactly when this is true. */
    public function renewalOffShown(): bool { return $this->renewalOff && !in_array('adopt.pending',$this->stateKeys,true); }
    /** Customer list rows: see DomainSummaryBadges. Callers pass the evidence and states their view shows.
     * A view with a Renewal column passes $renewalColumn, so "Renewal off" is not repeated as a chip. */
    public function listBadges(array $evidence,array $states,bool $renewalColumn=false): array
    { return DomainSummaryBadges::compact($evidence,$states,$renewalColumn && $this->renewalOffShown()?['adopt.renewal_off']:[]); }
}
