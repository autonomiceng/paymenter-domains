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
            self::Disabled => 'Adoption is not enabled yet.',
            self::VerificationChanged => 'Verification details changed or expired. Verify again.',
            self::RequestChanged => 'Adoption request changed. Verify again.',
            self::Permission => 'You do not have permission to adopt registrations.',
            self::ResultReview => 'Adoption result needs staff review.',
            self::IdentityReview => 'Registration identity needs staff review.',
            self::HoldingReview => 'Adoption holding requires review.',
        };
    }
}
