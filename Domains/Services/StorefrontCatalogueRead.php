<?php
// Display read model adapted from Enom 6119f1c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\SaleSelection;
use Paymenter\Extensions\Servers\Domains\Models\Price;

/** One public projection invocation only. Never retained on Storefront, cached or used as checkout authority. */
final class StorefrontCatalogueRead
{
    private array $endings = [];
    private array $actions = [];

    public function __construct(private readonly SellerPolicies $sellers, private readonly NativeCatalogue $native,
        private readonly \Paymenter\Extensions\Servers\Domains\Contracts\Clock $clock) {}

    public function offer(string $ending, string $action, int $term): array
    {
        if (!array_key_exists($ending, $this->endings)) {
            // Cache failure too: one corrupt ending cannot amplify reads for every term.
            $this->endings[$ending] = null;
            [$policy, $account, $tld] = $this->sellers->displayCatalogue($ending);
            $binding = CatalogueAcceptances::binding($account);
            $accepted = CatalogueAcceptances::load($binding);
            $rule = (new PricingRules)->rule($tld);
            $prices = Price::where(['server_id' => $policy->server_id, 'account_fingerprint' => $binding->fingerprint,
                'tld' => $ending, 'currency' => 'USD'])->get()->keyBy(static fn (Price $p) => $p->action.':'.$p->term);
            $products = $this->native->display($tld);
            $this->endings[$ending] = compact('policy', 'tld', 'accepted', 'prices', 'products', 'rule');
        }
        $read = $this->endings[$ending];
        if (!$read) { throw new \DomainException('Offer unavailable.'); }
        extract($read);
        $key = $policy->server_id.':'.$policy->account_fingerprint.':'.$ending.':'.$action;
        if (!array_key_exists($key, $this->actions)) {
            $this->actions[$key] = null;
            $product = $products[$action] ?? null;
            if (!$product || $product['product']->stock === 0) { throw new \DomainException('Offer unavailable.'); }
            $this->actions[$key] = $product;
        }
        $product = $this->actions[$key];
        if (!$product || !in_array($action, ['register', 'transfer'], true) || !$policy->{$action.'_enabled'}
            || $term < 1 || $term > 10 || ($action === 'transfer' && $term !== 1) || !$tld->enabled || !$tld->renew_enabled
            || !$tld->{$action.'_enabled'} || ($action === 'register' && ($term < $tld->min_years || $term > $tld->max_years))) {
            throw new \DomainException('Offer unavailable.');
        }
        $plan = $product['plans'][$term] ?? throw new \DomainException('Offer unavailable.');
        $renew = $this->price($prices->get('renew:1'), $rule);
        $price = $this->price($prices->get($action.':'.$term), $rule);
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
    private function price(?Price $price, array $rule): string
    {
        $now = $this->clock->now();
        if (!$price || $price->wholesale === null || $price->retail === null || $price->hold_reason !== null
            || $price->unit !== 'term_total' || !$price->fresh_until || $price->observed_at > $now || $price->fresh_until <= $now) {
            throw new \DomainException('No current eligible price.');
        }
        if (($price->eligibility['rule_hash'] ?? null) !== hash('sha256', json_encode($rule, JSON_THROW_ON_ERROR))) {
            throw new \DomainException('Price rule changed; republication required.');
        }
        PriceCatalogue::cost($price->wholesale);
        return PriceCatalogue::retail($price->retail);
    }
}
