<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
final class RuntimeIneligible extends \DomainException
{
    public function __construct(public readonly RuntimeReason $reason) { parent::__construct(match($reason){RuntimeReason::SpendingPaused=>'Renewal spending requires review.',RuntimeReason::LifecycleNotReady=>'Renewal billing lifecycle requires review.',RuntimeReason::ProcessingOff=>'Registrar processing is disabled.',RuntimeReason::BillingOff=>'Requested renewal billing gate is disabled.',RuntimeReason::MissingAnchor=>'Confirmed expiry required.',RuntimeReason::OutsideCalendar=>'Outside renewal invoice calendar.',default=>$reason->value}); }
}
