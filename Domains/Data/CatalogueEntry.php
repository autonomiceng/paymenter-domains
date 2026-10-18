<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Null batch entries mean failed reads. Only CatalogueRate::perYear=null means positive no-price. */
final readonly class CatalogueEntry
{
    public array $rates;
    public array $terms;
    public function __construct(public AccountBinding $binding, public string $tld, array $rates, array $terms,
        public bool $registrySupported, public bool $renewalSupported, public array $registryFields = [])
    {
        CanonicalName::parse('example.'.$tld, [$tld]);
        $seen = []; $copy = [];
        foreach ($rates as $rate) {
            if (!$rate instanceof CatalogueRate || !$rate->persistable || isset($seen[$rate->action.':'.$rate->term])) {
                throw new \InvalidArgumentException('Invalid catalogue evidence.');
            }
            $seen[$rate->action.':'.$rate->term] = true; $copy[] = $rate;
        }
        if (!$copy) { throw new \DomainException('Explicit catalogue evidence required.'); }
        $this->rates = $copy;
        $this->terms = array_map(static fn (int $term): int => $term, (new Capability($terms ? Support::Supported : Support::Unsupported, $terms))->terms);
    }
    public function __debugInfo(): array { return ['tld' => $this->tld]; }
    public function __serialize(): never { throw new \LogicException('Catalogue evidence is server-only.'); }
}
