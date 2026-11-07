<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\{Category, Plan, Product};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{NativeActivationInformation, NativeCatalogue, PricingRule};

/** One-way native projection. Caller owns native locks before server/account/policy/offer locks. */
final class Projection
{
    public function __construct(private readonly NativeCatalogue $native, private readonly ?\Closure $feature = null) {}

    public function lock(array $seeds): array
    {
        $category = Category::where('slug', 'domains')->lockForUpdate()->first();
        $category ??= Category::create(['name' => 'Domains', 'slug' => 'domains', 'description' => 'Domain names.']);
        $slugs = [];
        foreach ($seeds as $seed) { foreach (['register', 'transfer'] as $a) { $slugs[] = NativeCatalogue::slug($seed, $a); } }
        $ids = Product::where(static function ($q) use ($seeds, $slugs): void {
            $q->whereIn('slug', $slugs)->orWhereHas('settings', static fn ($s) => $s->where('key', 'domains_tld_id')->whereIn('value', array_map(static fn ($t) => (string) $t->id, $seeds)));
        })->pluck('id');
        $products = Product::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        $plans = Plan::where('priceable_type', (new Product)->getMorphClass())->whereIn('priceable_id', $ids)->orderBy('id')->lockForUpdate()->get();
        \App\Models\Price::whereIn('plan_id', $plans->modelKeys())->orderBy('id')->lockForUpdate()->get();
        return [$category, $products];
    }

    public function write(Tld $tld, Snapshot $snapshot, \DateTimeImmutable $now, array $locked, bool $retailUpgrade = false): void
    {
        [$category, $products] = $locked; $byAction = [];
        foreach ($products as $product) {
            $map = $this->native->mapping($product) ?? throw new \DomainException('Reserved domain slug collision.');
            if ($map['tld']->id !== $tld->id) { continue; }
            if (isset($byAction[$map['action']])) { throw new \DomainException('Duplicate domain product.'); }
            $byAction[$map['action']] = $product;
        }
        $evaluator = new Evaluator;
        foreach (['register', 'transfer'] as $action) {
            $available = [];
            $featureReady = !$this->feature || ($this->feature)($snapshot->feed->binding(),$action) && ($this->feature)($snapshot->feed->binding(),'renew');
            foreach ($action === 'register' ? range(1, 10) : [1] as $term) {
                $q = new Query($snapshot->feed->binding(), [$tld->tld], $action, $term);
                $decision = $evaluator->evaluate($snapshot, $q, Context::Public, $now);
                if ($featureReady && $decision->available) { $available[$term] = $decision->retail; }
            }
            $product = $byAction[$action] ?? null;
            if (!$product && !$available) { continue; }
            if (!$product) {
                $product = Product::create(['name' => ($action === 'register' ? 'Register .' : 'Transfer .').$tld->tld,
                    'slug' => NativeCatalogue::slug($tld, $action), 'category_id' => $category->id, 'server_id' => $tld->server_id,
                    'hidden' => true, 'stock' => null, 'per_user_limit' => 0, 'allow_quantity' => 'separated']);
                foreach (['domains_catalogue' => '1', 'domains_tld_id' => (string) $tld->id,
                    'domains_action' => $action, 'domains_registrar' => $tld->registrar_key] as $key => $value) {
                    $product->settings()->create(['key' => $key, 'value' => $value, 'type' => 'string', 'encrypted' => false]);
                }
                NativeActivationInformation::initialize($product);
                if ($product->isDirty()) { $product->save(); }
            }
            foreach (array_unique([1, ...array_keys($available), ...$product->plans()->pluck('billing_period')->all()]) as $term) {
                $plans = $product->plans()->where('billing_period', $term)->get();
                if ($plans->count() > 1) { throw new \DomainException('Duplicate domain plan.'); }
                $plan = $plans->first(); $retained = $plan !== null;
                $plan ??= $product->plans()->create(['name' => $term.' '.($term === 1 ? 'year' : 'years'),
                    'type' => 'recurring', 'billing_period' => $term, 'billing_unit' => 'year']);
                $this->native->mapping($product, $plan);
                // Rule upgrades project published retail into existing identities even while sales are
                // paused. This grants no sale or new term; admission still uses the normal evaluator.
                $retail = $available[$term] ?? ($retailUpgrade && $retained ? ($snapshot->retail[$action.':'.$term] ?? null) : null);
                if ($retail === null) { continue; }
                $plan->sort = $term;
                if ($plan->isDirty()) { $plan->save(); }
                $rows = $plan->prices()->select('*')->selectRaw('CAST(price AS CHAR) AS price, CAST(setup_fee AS CHAR) AS setup_fee')->where('currency_code', 'USD')->get();
                if ($rows->count() > 1) { throw new \DomainException('Duplicate domain price.'); }
                $price = $rows->first() ?? $plan->prices()->make(['currency_code' => 'USD']);
                // Native decimal casts may have different scale. Compare exact cents before assigning.
                if (!$price->exists || PricingRule::minor($price->getRawOriginal('price')) !== PricingRule::units($retail, 2)
                    || PricingRule::minor($price->getRawOriginal('setup_fee')) !== 0) {
                    $price->fill(['price' => $retail, 'setup_fee' => '0.00'])->save();
                }
            }
            $product->stock = null;
            if ($product->isDirty()) { $product->save(); }
        }
    }
}
