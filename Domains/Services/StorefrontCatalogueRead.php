<?php
// Display read model adapted from Enom 6119f1c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\{Environment, SaleSelection};
use Paymenter\Extensions\Servers\Domains\Models\Price;

/** One public projection invocation only. Never retained on Storefront, cached or used as checkout authority. */
final class StorefrontCatalogueRead
{
    private array $endings = [];
    private array $actions = [];
    private array $acceptances = [];
    private ?array $catalogues = null;
    private array $products = [];
    private array $priceRows = [];
    private ?array $defaultRule = null;
    private ?array $fee = null;
    private ?array $offers = null;

    public function __construct(private readonly SellerPolicies $sellers, private readonly NativeCatalogue $native,
        private readonly \Paymenter\Extensions\Servers\Domains\Contracts\Clock $clock, private readonly ?array $wanted = null,
        private readonly ?AcquisitionOffers $catalogue = null) {}

    private function prepare(string $ending): void
    {
        if ($this->catalogues !== null) { return; }
        $this->catalogues = $this->sellers->displayCatalogues($this->wanted ?? [$ending]);
        $tlds = array_column($this->catalogues, 2);
        $this->products = $this->native->displayBatch($tlds);
        $this->priceRows = Price::whereIn('server_id', array_unique(array_column($tlds, 'server_id')))
            ->whereIn('tld', array_keys($this->catalogues))->where('currency', 'USD')->get()
            ->groupBy(static fn (Price $p) => $p->server_id.':'.$p->tld)->all();
    }

    private function ending(string $ending): array
    {
        if (!array_key_exists($ending, $this->endings)) {
            // Cache failure too: one corrupt ending cannot amplify reads for every term.
            $this->endings[$ending] = null;
            $this->prepare($ending);
            [$policy, $account, $tld] = $this->catalogues[$ending] ?? throw new \DomainException('Offer unavailable.');
            $binding = CatalogueAcceptances::binding($account);
            // Each account can have hundreds of endings; share its acceptance within this display snapshot.
            $accepted = $this->acceptances[$binding->serverId.':'.$binding->fingerprint] ??= CatalogueAcceptances::load($binding);
            $rules = new PricingRules; $serverId = $policy->server_id;
            $rule = $tld->use_default_rule ? ($this->defaultRule ??= $rules->defaultRule())
                : $rules->rule($tld, fee: $tld->pricing_mode === 'markup_fee_percent' ? ($this->fee ??= CardFee::read()) : null);
            $ruleHash = hash('sha256', json_encode($rule, JSON_THROW_ON_ERROR));
            $prices = ($this->priceRows[$serverId.':'.$ending] ?? collect())->where('account_fingerprint', $binding->fingerprint)
                ->keyBy(static fn (Price $p) => $p->action.':'.$p->term);
            $products = $this->products[$tld->id] ?? throw new \DomainException('Invalid domain product mapping.');
            $registrationsOn = $binding->registrarKey !== 'enom' || $binding->environment !== Environment::Live || EnomTestRegistration::sellable($binding);
            $transfersOn = $policy->transfer_enabled && $tld->transfer_enabled
                && ($binding->registrarKey !== 'enom' || $binding->environment !== Environment::Live || EnomTestTransfer::accepted($binding));
            $this->endings[$ending] = compact('policy', 'tld', 'accepted', 'prices', 'products', 'ruleHash', 'registrationsOn', 'transfersOn');
        }
        $read = $this->endings[$ending];
        if (!$read) { throw new \DomainException('Offer unavailable.'); }
        return $read;
    }

    /** Keep the saved-price fallback behind the same Live acceptance as a priced transfer. */
    public function transfersOn(string $ending): bool
    {
        if ($this->catalogue) { try { $this->offer($ending, 'transfer', 1); return true; } catch (\DomainException) { return false; } }
        return $this->ending($ending)['transfersOn'];
    }

    public function offer(string $ending, string $action, int $term): array
    {
        if ($this->catalogue) {
            $this->offers ??= $this->catalogue->display($this->wanted ?? [$ending]);
            return $this->offers[$ending][$action.':'.$term] ?? throw new \DomainException('Offer unavailable.');
        }
        extract($this->ending($ending));
        $key = $policy->server_id.':'.$policy->account_fingerprint.':'.$ending.':'.$action;
        if (!array_key_exists($key, $this->actions)) {
            $this->actions[$key] = null;
            $product = $products[$action] ?? null;
            if (!$product || $product['product']->stock === 0) { throw new \DomainException('Offer unavailable.'); }
            $this->actions[$key] = $product;
        }
        $product = $this->actions[$key];
        if (!$product || !in_array($action, ['register', 'transfer'], true) || !$policy->{$action.'_enabled'}
            || $term < 1 || $term > 10 || ($action === 'transfer' && ($term !== 1 || !$transfersOn)) || !$tld->enabled || !$tld->renew_enabled
            || !$tld->{$action.'_enabled'} || ($action === 'register' && (!$registrationsOn || $term < $tld->min_years || $term > $tld->max_years))) {
            throw new \DomainException('Offer unavailable.');
        }
        $plan = $product['plans'][$term] ?? throw new \DomainException('Offer unavailable.');
        $renew = $this->price($prices->get('renew:1'), $ruleHash);
        $price = $this->price($prices->get($action.':'.$term), $ruleHash);
        PublicationEligibility::requireEvidence($tld, $action, $term, $accepted, $prices->get('renew:1'), $prices->get($action.':'.$term));
        $native = $plan->prices->where('currency_code', 'USD')->sole();
        if (RetailLine::decimal($native->price) !== $price || RetailLine::decimal($native->setup_fee) !== '0.00'
            || PricingRule::minor($price) <= 0) { throw new \DomainException('Offer changed.'); }
        $selection = new SaleSelection($ending, $policy->server_id, $policy->registrar_key, $policy->account_fingerprint,
            $policy->revision, $action, $term, 'USD', $prices->get($action.':'.$term)->id, $prices->get($action.':'.$term)->revision);
        return ['selection' => $selection, 'product' => $product['product'], 'plan' => $plan,
            'price' => Storefront::money($price), 'renew' => Storefront::money($renew)];
    }

    /** Display-only mirror of current(): public exact-money APIs, no holder-retail fallback. */
    private function price(?Price $price, string $ruleHash): string
    {
        return PriceCatalogue::currentEvidence($price, $ruleHash, $this->clock->now())->retail;
    }
}
