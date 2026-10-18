<?php
// Adapted from Enom 444f31c Services/Catalogue.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Category, Plan, Product, Server};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};

/** Permanent account/TLD/action products. Publication never changes an existing dispatch route. */
final class NativeCatalogue
{
    private const KEYS = ['domains_catalogue', 'domains_tld_id', 'domains_action', 'domains_registrar'];
    public function __construct(private readonly PriceCatalogue $prices) {}

    public static function slug(Tld $tld, string $action): string
    {
        if (!in_array($action, ['register', 'transfer'], true)) { throw new \InvalidArgumentException('Unknown acquisition action.'); }
        return 'domains-'.$tld->registrar_key.'-s'.$tld->server_id.'-'.$tld->tld.'-'.($action === 'register' ? 'registration' : 'transfer');
    }

    public function product(Tld $tld, string $action): ?Product
    {
        $rows = $this->products($tld)->get()->filter(function (Product $p) use ($action): bool {
            return ($this->mapping($p) ?? throw new \DomainException('Reserved domain slug collision.'))['action'] === $action;
        });
        if ($rows->count() > 1) { throw new \DomainException('Duplicate domain product.'); }
        $product = $rows->first();
        if ($product) {
            $mapping = $this->mapping($product);
            if (!$mapping || $mapping['tld']->id !== $tld->id || $mapping['action'] !== $action) { throw new \DomainException('Domain product identity changed.'); }
        }
        return $product;
    }

    private function products(Tld $tld)
    {
        return Product::where(function ($q) use ($tld): void {
            $q->whereIn('slug', [self::slug($tld, 'register'), self::slug($tld, 'transfer')])
                ->orWhereHas('settings', static fn ($s) => $s->where('key', 'domains_tld_id')->where('value', (string) $tld->id));
        });
    }

    /** Invalid or partially removed markers fail closed, including on a Domains server. */
    public function mapping(Product $product, ?Plan $plan = null): ?array
    {
        $settings = $product->settings()->whereIn('key', self::KEYS)->get();
        $server = $product->server()->first();
        if ($settings->isEmpty() && $server?->extension !== 'Domains') { return null; }
        $value = static fn (string $key) => $settings->where('key', $key)->count() === 1 ? $settings->firstWhere('key', $key)->value : null;
        $id = $value('domains_tld_id'); $action = $value('domains_action');
        $tld = is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) ? Tld::find((int) $id) : null;
        $account = $tld ? Account::where('server_id', $tld->server_id)->first() : null;
        if ($settings->count() !== 4 || $settings->contains(static fn ($s): bool => $s->type !== 'string' || $s->encrypted)
            || $value('domains_catalogue') !== '1' || !$tld || !$account || $server?->extension !== 'Domains'
            || (int) $product->server_id !== (int) $tld->server_id || $account->account_fingerprint !== $tld->account_fingerprint
            || $account->registrar_key !== $tld->registrar_key || $value('domains_registrar') !== $tld->registrar_key
            || !in_array($action, ['register', 'transfer'], true) || $product->slug !== self::slug($tld, $action)
            || !$product->hidden || $product->allow_quantity !== 'separated' || $this->unsafeLinks($product)) { throw new \DomainException('Invalid domain product mapping.'); }
        if ($plan && ($plan->priceable_type !== $product->getMorphClass() || (int) $plan->priceable_id !== (int) $product->id
            || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || $plan->billing_period < 1 || $plan->billing_period > 10
            || ($action === 'transfer' && $plan->billing_period !== 1))) { throw new \DomainException('Invalid domain plan mapping.'); }
        return ['tld' => $tld, 'action' => $action, 'term' => $plan?->billing_period];
    }

    private function unsafeLinks(Product $product): bool
    {
        return DB::table('product_upgrades')->where('product_id', $product->id)->orWhere('upgrade_id', $product->id)->exists()
            || DB::table('config_option_products')->where('product_id', $product->id)->exists();
    }

    /** The annual holding plan belongs to the SAME permanent acquisition product. No publication under checkout locks. */
    public function annualPlan(Product $product): Plan
    {
        $this->mapping($product);
        return $product->plans()->where(['type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1])->sole();
    }

    public function publish(int $tldId, \DateTimeImmutable $now): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Native publication owns its lock prefix.'); }
        $seed = Tld::findOrFail($tldId);
        DB::transaction(function () use ($seed, $now): void {
            // Native category mutex serializes first publication: native slugs have no unique index.
            $category = Category::where('slug', 'domains')->lockForUpdate()->first();
            $category ??= Category::create(['name' => 'Domains', 'slug' => 'domains', 'description' => 'Domain names.']);
            // Native products, plans and prices precede every Domains lock. No retries or core patches.
            $ids = $this->products($seed)->pluck('id'); // Discovery is unlocked: slug has no native index.
            $products = Product::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($products as $product) {
                if ($this->unsafeLinks($product)) { throw new \DomainException('Domain products cannot use upgrades or config options.'); }
            }
            $plans = Plan::where('priceable_type', (new Product)->getMorphClass())->whereIn('priceable_id', $products->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            \App\Models\Price::whereIn('plan_id', $plans->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            $server = Server::whereKey($seed->server_id)->lockForUpdate()->firstOrFail();
            $account = Account::where('server_id', $seed->server_id)->lockForUpdate()->firstOrFail();
            $tld = Tld::whereKey($seed->id)->lockForUpdate()->firstOrFail();
            if (!$server->enabled || $server->extension !== 'Domains' || $tld->account_fingerprint !== $account->account_fingerprint
                || $tld->registrar_key !== $account->registrar_key) { throw new \DomainException('Catalogue account unavailable.'); }
            foreach ($products as $product) {
                $mapping = $this->mapping($product);
                if (!$mapping || $mapping['tld']->id !== $tld->id) { throw new \DomainException('Reserved domain slug collision.'); }
            }
            foreach (['register', 'transfer'] as $action) {
                $matches = $products->where('slug', self::slug($tld, $action));
                if ($matches->count() > 1) { throw new \DomainException('Duplicate domain product.'); }
                $product = $matches->first();
                if ($product) { $this->mapping($product); }
                else {
                    $product = Product::create(['name' => ($action === 'register' ? 'Register .' : 'Transfer .').$tld->tld,
                        'slug' => self::slug($tld, $action), 'category_id' => $category->id, 'server_id' => $tld->server_id,
                        'hidden' => true, 'stock' => 0, 'per_user_limit' => 0, 'allow_quantity' => 'separated']);
                    foreach (array_combine(self::KEYS, ['1', (string) $tld->id, $action, $tld->registrar_key]) as $key => $value) {
                        $product->settings()->create(['key' => $key, 'value' => $value, 'type' => 'string', 'encrypted' => false]);
                    }
                }
                $available = [];
                foreach ($action === 'register' ? range(1, 10) : [1] as $term) {
                    if (!$tld->enabled || !$tld->{$action.'_enabled'} || !$tld->renew_enabled
                        || ($action === 'register' && ($term < $tld->min_years || $term > $tld->max_years))) { continue; }
                    try {
                        PublicationEligibility::require($tld, $action, $term, true);
                        $this->prices->current((int) $tld->server_id, $tld->account_fingerprint, $tld->tld, 'renew', 1, 'USD', $now, true, $tld);
                        $available[$term] = $this->prices->current((int) $tld->server_id, $tld->account_fingerprint, $tld->tld, $action, $term, 'USD', $now, true, $tld)->retail;
                    } catch (\DomainException) { /* Retain plan identities; withdraw only their acquisition prices. */ }
                }
                foreach (array_unique([1, ...array_keys($available), ...$product->plans()->pluck('billing_period')->all()]) as $term) {
                    $existing = $product->plans()->where('billing_period', $term)->get();
                    if ($existing->count() > 1) { throw new \DomainException('Duplicate domain plan.'); }
                    $plan = $existing->first() ?? $product->plans()->create(['name' => $term.' '.($term === 1 ? 'year' : 'years'),
                        'type' => 'recurring', 'billing_period' => $term, 'billing_unit' => 'year']);
                    $this->mapping($product, $plan);
                    $plan->sort = isset($available[$term]) ? $term : 100 + $term; $plan->save();
                    $plan->prices()->whereNot('currency_code', 'USD')->delete();
                    if (isset($available[$term])) {
                        $rows = $plan->prices()->where('currency_code', 'USD')->get();
                        if ($rows->count() > 1) { throw new \DomainException('Duplicate domain price.'); }
                        $price = $rows->first() ?? $plan->prices()->make(['currency_code' => 'USD']);
                        $price->fill(['price' => $available[$term], 'setup_fee' => '0.00'])->save();
                    } else { $plan->prices()->delete(); }
                }
                $product->stock = $available !== [] ? null : 0; $product->save();
            }
        });
    }
}
