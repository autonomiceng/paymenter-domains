<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Evidence keys that only say a fact is unknown. Views never show them; see StatusPill for the one status pill. */
final class DomainSummaryBadges
{
    public const GAPS=['domain.registration.unknown','domain.dns.unknown','domain.points.unknown','domain.points.partial'];
}
