<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Domain;

/** Import ownership/state is independent of domain-specific renewal cost and non-premium proof. */
final class AdoptionRenewalEligibility
{
    /** NS16 must supply validated domain-specific evidence before replacing this refusal. No config bypass. */
    public static function forRegistrar(string $registrar): array
    {
        return $registrar === 'enom' ? ['eligible'=>true,'reason'=>null]
            : ['eligible'=>false,'reason'=>'imported_renewal_unproven'];
    }
    public static function requireBilling(Domain $domain): void
    {
        if (($domain->adoption_id !== null || $domain->provenance === 'adopted')
            && !self::forRegistrar($domain->registrar_key)['eligible']) {
            throw new \DomainException('Imported renewal eligibility is not proven.');
        }
    }
}
