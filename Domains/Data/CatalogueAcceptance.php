<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Trusted server-side acceptance record, never a customer input or provider identity proof. */
final readonly class CatalogueAcceptance
{
    public array $endings;
    public function __construct(public AccountBinding $binding, public int $revision, array $endings)
    {
        if ($revision < 1 || count($endings) > 500) { throw new \InvalidArgumentException('Invalid catalogue acceptance.'); }
        $copy = [];
        foreach ($endings as $tld => $row) {
            if (!is_string($tld) || strlen($tld) > 63) { throw new \InvalidArgumentException('Invalid accepted ending.'); }
            CanonicalName::parse('example.'.$tld, [$tld]);
            if (!is_array($row) || array_diff(array_keys($row), ['register', 'renew', 'transfer', 'registry', 'renewal_safe'])
                || !is_bool($row['registry'] ?? null) || !is_bool($row['renewal_safe'] ?? null)) {
                throw new \InvalidArgumentException('Explicit registry and renewal acceptance required.');
            }
            foreach (['register', 'renew', 'transfer'] as $action) {
                $terms = $row[$action] ?? null;
                if (!is_array($terms) || !array_is_list($terms)) { throw new \InvalidArgumentException('Explicit action terms required.'); }
                new Capability($terms ? Support::Supported : Support::Unsupported, $terms);
                if (($action !== 'register' || $binding->registrarKey === 'namesilo') && array_diff($terms, [1])) {
                    throw new \InvalidArgumentException('Only accepted annual catalogue units are supported.');
                }
                $copy[$tld][$action] = array_map(static fn (int $term): int => $term, $terms);
            }
            $copy[$tld]['registry'] = $row['registry']; $copy[$tld]['renewal_safe'] = $row['renewal_safe'];
        }
        $this->endings = $copy;
    }
    public function __debugInfo(): array { return ['revision' => $this->revision]; }
    public function __serialize(): never { throw new \LogicException('Catalogue acceptance is server-only.'); }
}
