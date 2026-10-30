<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data\Adoption;
/** Stable confirmation refusals, raised only before the adoption transaction commits. */
enum RefusalCode: string
{
    case Disabled = 'disabled';
    case VerificationChanged = 'verification_changed';
    case RequestChanged = 'request_changed';
    case Permission = 'permission';
    case ResultReview = 'result_review';
    case IdentityReview = 'identity_review';
    case HoldingReview = 'holding_review';

    public function message(): string
    {
        return match ($this) {
            self::Disabled => 'Import is off on this site.',
            self::VerificationChanged => 'Details changed or expired. Check the domain again.',
            self::RequestChanged => 'Import request changed. Check the domain again.',
            self::Permission => "You don't have permission to import domains.",
            self::ResultReview => 'Import result needs a check.',
            self::IdentityReview => 'Domain records need a check.',
            self::HoldingReview => 'Domain records need a check.',
        };
    }
}
