<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, Tld};

final class PublicationEligibility
{
    /** Called under the seller's server/account/policy/TLD prefix for admission; no provider reads. */
    public static function require(Tld $tld, string $action, int $term, bool $lock = false): void
    {
        $account = Account::where('server_id', $tld->server_id)->firstOrFail();
        $binding = CatalogueAcceptances::binding($account);
        $accepted = CatalogueAcceptances::load($binding, $lock); $prices = [];
        foreach ([['renew', 1], [$action, $term]] as [$kind, $years]) {
            $query = Price::where(['server_id' => $tld->server_id, 'account_fingerprint' => $binding->fingerprint,
                'tld' => $tld->tld, 'action' => $kind, 'term' => $years, 'currency' => 'USD']);
            if ($lock) { $query->lockForUpdate(); }
            $price = $query->first();
            $prices[] = $price;
        }
        self::requireEvidence($tld, $action, $term, $accepted, ...$prices);
    }

    /** Pure validation shared by strict locking reads and a single public display snapshot. */
    public static function requireEvidence(Tld $tld, string $action, int $term,
        \Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance $accepted, ?Price $renewal, ?Price $acquisition): void
    {
        if (!RegistryInputs::supported($tld->registrar_key, $tld->tld, $tld->registry_fields)) { throw new \DomainException('Registry details are not supported for this ending.'); }
        $row = $accepted->endings[$tld->tld] ?? [];
        if (!($row['registry'] ?? false) || !($row['renewal_safe'] ?? false) || !in_array(1, $row['renew'] ?? [], true)
            || !in_array($term, $row[$action] ?? [], true)) { throw new \DomainException('Acquisition acceptance unavailable.'); }
        foreach ([$renewal, $acquisition] as $price) {
            if (!$price) { throw new \DomainException('Catalogue publication evidence missing.'); }
            $proof = $price->eligibility;
            if (($proof['catalogue_revision'] ?? null) !== $accepted->revision || !($proof['registry'] ?? false)
                || !($proof['renewal_safe'] ?? false) || ($action === 'register' && !in_array($term, $proof['accepted_terms'] ?? [], true))) {
                throw new \DomainException('Catalogue publication evidence changed.');
            }
        }
    }
}
