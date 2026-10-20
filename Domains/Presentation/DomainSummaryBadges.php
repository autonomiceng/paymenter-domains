<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** List-density chips: states first, then known evidence, then one chip for every missing axis.
 * A freshness chip already explains missing evidence; otherwise one neutral gap chip does. Never adds a label
 * the summary did not supply, so unknown or partial evidence keeps its W5 guarantees. Detail views keep each axis. */
final class DomainSummaryBadges
{
    public const GAPS=['domain.registration.unknown','domain.dns.unknown','domain.points.unknown','domain.points.partial'];
    /** Priority order: never checked, then out of date, then partly complete. */
    public const FRESHNESS=['watch.not_checked','watch.stale','watch.partial'];
    public const GAP='domain.details.partial';
    public static function compact(array $evidence,array $states): array
    {
        $fresh=array_values(array_intersect(self::FRESHNESS,$states));
        $known=array_values(array_diff($evidence,self::GAPS));
        $gap=$fresh?[$fresh[0]]:(array_intersect($evidence,self::GAPS)?[self::GAP]:[]);
        return [...array_values(array_diff($states,self::FRESHNESS)),...$known,...$gap];
    }
}
