<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Staff\Release;
/** Why a held request cannot be released by staff. */
enum ReleaseRefusal: string
{
    /** Not a held purchase, a contact hold, an automatic balance retry, or a hold this site cannot release. */
    case OriginalRecovery='original_recovery';
    /** The balance retry's purchase proof changed. */
    case PurchaseProof='purchase_proof';
    /** The retained contact step cannot go again; ReleaseFacts::$contactBlocked says why. */
    case ContactStep='contact_step';

    public function message(ReleaseFacts $facts): string
    {
        return match($this){
            self::OriginalRecovery=>'This hold requires its original recovery or evidence path.',
            self::PurchaseProof=>'Original purchase proof still needs review.',
            self::ContactStep=>(string)$facts->contactBlocked,
        };
    }
}
