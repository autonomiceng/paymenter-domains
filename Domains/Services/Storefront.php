<?php
// Adapted from Enom 444f31c DomainController/DomainOrders/Catalogue; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Action, CanonicalName, Disposition, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, SalesPolicy, Tld};

/** Explicit customer projection. No model, seller, cost, provider text or read evidence reaches Blade. */
final class Storefront
{
    public function __construct(private readonly SellerPolicies $sellers, private readonly PriceCatalogue $prices,
        private readonly NativeCatalogue $native, private readonly AccountBindings $bindings,
        private readonly CheckoutEligibility $eligibility, private readonly Clock $clock) {}

    public function endings(): array
    {
        return (new StorefrontCatalogueCache($this->clock))->remember(fn () => $this->catalogueRows());
    }

    private function catalogueRows(): array
    {
        $rows = [];
        foreach (SalesPolicy::orderBy('tld')->pluck('tld') as $ending) {
            $register = $transfer = null; $terms = [];
            foreach (range(1, 10) as $term) {
                try { $offer = $this->offer($ending, 'register', $term); $terms[] = $term; if ($term === 1) { $register = $offer['price']; } }
                catch (\Throwable) { /* A held term does not imply an unavailable longer term. */ }
            }
            try { $transfer = $this->offer($ending, 'transfer', 1)['price']; } catch (\Throwable) {}
            if (!$terms && !$transfer) { continue; }
            $action = $terms ? 'register' : 'transfer'; $term = $terms[0] ?? 1;
            try { $renew = $this->offer($ending, $action, $term)['renew']; } catch (\Throwable) { continue; }
            $rows[] = ['tld' => $ending, 'price' => $register, 'register' => $register, 'transfer' => $transfer,
                'renew' => $renew, 'terms' => $terms];
        }
        return $rows;
    }

    public function testEnvironment(array $rows): bool|string
    {
        $ids = SalesPolicy::whereIn('tld', array_column($rows, 'tld'))->pluck('server_id', 'tld');
        $environments = Account::whereIn('server_id', $ids->values())->pluck('environment', 'server_id');
        $tests = $ids->filter(static fn ($id) => ($environments[$id] ?? null) === 'test')->count();
        return $tests === 0 ? false : ($tests === count($rows) ? true : 'some');
    }

    /** Safe public result only. Any reservation has the SAME response regardless of owner or lifecycle. */
    public function check(string $input): array
    {
        $name = CanonicalName::normalize($input);
        $ending = CanonicalName::parse($name, SalesPolicy::pluck('tld')->all())['tld'];
        $base = ['domain' => $name, 'sld' => substr($name, 0, -strlen($ending)-1), 'tld' => $ending, 'status' => 'taken'];
        if (Domain::where('reservation_name', $name)->exists()) { return $base; }
        $offers = [];
        foreach (range(1, 10) as $term) {
            try { $offers[$term] = $this->offer($ending, 'register', $term); } catch (\Throwable) {}
        }
        $transfer = null;
        try { $transfer = $this->offer($ending, 'transfer', 1); } catch (\Throwable) {}
        if (!$offers && !$transfer) { return [...$base, 'status' => 'held']; }
        $selection = ($offers ? reset($offers) : $transfer)['selection'];
        $driver = $this->bindings->reader($selection->serverId);
        if ($driver->binding()->fingerprint !== $selection->fingerprint) { throw new \DomainException('Search account changed.'); }
        $started = $this->clock->now();
        // Installed contexts share interactive headroom with background work.
        $context = new RequestContext($driver->binding(), RequestOrigin::Interactive, $started->modify('+10 seconds'), new RequestBudget(1), $this->clock, Runtime::limiter());
        $r = $driver->availability(new Query($name, Action::Register, $offers ? (int) array_key_first($offers) : 1), $context);
        $driver->binding()->requireMatch($r->binding);
        if ($r->disposition !== Disposition::Accepted || $context->now() >= $context->deadline) { return [...$base, 'status' => 'error']; }
        if ($r->evidence()->premium === true) { return [...$base, 'status' => 'premium']; }
        if ($r->evidence()->premium !== false || $r->evidence()->available === null) { return [...$base, 'status' => 'error']; }
        if ($r->evidence()->available) {
            if (!$offers) { return [...$base, 'status' => 'held']; }
            $first = reset($offers);
            return [...$base, 'status' => 'available', 'price' => $first['price'], 'renew' => $first['renew'],
                'term' => (int) array_key_first($offers), 'terms' => array_map(static fn ($offer) => $offer['price'], $offers)];
        }
        if ($transfer) {
            try {
                $this->eligibility->observe($name, $transfer['selection'], ['storefront']);
                return [...$base, 'status' => 'transferable', 'price' => $transfer['price'], 'term' => 1];
            } catch (\Throwable) { /* An unverified transfer never gets an action. */ }
        }
        return $base;
    }

    /** Server-only resolution; links and forms never carry native identities. Cart admission rechecks independently. */
    public function offer(string $ending, string $action, int $term): array
    {
        $s = $this->sellers->selection($ending, $action, $term, $this->clock->now());
        $tld = Tld::where(['server_id' => $s->serverId, 'tld' => $ending, 'account_fingerprint' => $s->fingerprint])->firstOrFail();
        $product = $this->native->product($tld, $action);
        if (!$product || $product->stock === 0) { throw new \DomainException('Offer unavailable.'); }
        $plan = $product->plans()->where('billing_period', $term)->sole(); $this->native->mapping($product, $plan);
        $p = $this->prices->current($s->serverId, $s->fingerprint, $ending, $action, $term, 'USD', $this->clock->now());
        $native = $plan->prices()->where('currency_code', 'USD')->sole();
        if (RetailLine::decimal($native->price) !== $p->retail || RetailLine::decimal($native->setup_fee) !== '0.00'
            || PricingRule::minor($p->retail) <= 0) { throw new \DomainException('Offer changed.'); }
        $renew = $this->prices->current($s->serverId, $s->fingerprint, $ending, 'renew', 1, 'USD', $this->clock->now());
        return ['selection' => $s, 'product' => $product, 'plan' => $plan, 'price' => self::money($p->retail), 'renew' => self::money($renew->retail)];
    }

    /** Exact two-decimal display; no float conversion even at the integer boundary. */
    public static function money(string $amount): string
    {
        $value = PriceCatalogue::retail($amount); [$whole, $fraction] = explode('.', $value);
        return '$'.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }
}
