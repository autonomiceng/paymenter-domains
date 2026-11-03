<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName};

/** Bounded account batch. Binding comes from the selected seller or the existing holding. */
final readonly class Query
{
    public function __construct(public AccountBinding $binding, public array $tlds, public string $action,
        public int $term = 1, public string $currency = 'USD', public array $expected = [])
    {
        if ($tlds === [] || count($tlds) > 500 || count(array_unique($tlds)) !== count($tlds)
            || !in_array($action, ['register', 'renew', 'transfer'], true) || $term < 1 || $term > 10
            || ($action !== 'register' && $term !== 1) || $currency !== 'USD') {
            throw new \InvalidArgumentException('Invalid offer query.');
        }
        foreach ($tlds as $tld) {
            if (!is_string($tld)) { throw new \InvalidArgumentException('Invalid offer ending.'); }
            CanonicalName::parse('example.'.$tld, [$tld]);
        }
        foreach ($expected as $tld => $revision) {
            if (!in_array($tld, $tlds, true) || !$revision instanceof Revision) { throw new \InvalidArgumentException('Invalid expected revision.'); }
        }
    }
}
