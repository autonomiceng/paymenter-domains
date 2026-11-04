<?php
// Adapted from Enom 444f31c Services/Catalogue.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Category, Plan, Product, Server};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};

/** Permanent account/TLD/action products. Publication never changes an existing dispatch route. */
class NativeCatalogue
{
    private const KEYS = ['domains_catalogue', 'domains_tld_id', 'domains_action', 'domains_registrar'];
    public function __construct(private readonly PriceCatalogue $prices) {}

    protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands
    {return \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::class);}
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
        return $this->mappingSettings($product, $settings, $plan);
    }

    private function mappingSettings(Product $product, \Illuminate\Support\Collection $settings, ?Plan $plan = null, ?array $display = null): ?array
    {
        $server = $display === null ? $product->server()->first() : $display['servers']->get($product->server_id);
        if ($settings->isEmpty() && $server?->extension !== 'Domains') { return null; }
        $value = static fn (string $key) => $settings->where('key', $key)->count() === 1 ? $settings->firstWhere('key', $key)->value : null;
        $id = $value('domains_tld_id'); $action = $value('domains_action');
        $tld = is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) ? ($display === null ? Tld::find((int) $id) : $display['tlds']->get((int) $id)) : null;
        $account = $tld ? ($display === null ? Account::where('server_id', $tld->server_id)->first() : $display['accounts']->get($tld->server_id)) : null;
        if ($settings->count() !== 4 || $settings->contains(static fn ($s): bool => $s->type !== 'string' || $s->encrypted)
            || $value('domains_catalogue') !== '1' || !$tld || !$account || $server?->extension !== 'Domains'
            || (int) $product->server_id !== (int) $tld->server_id || $account->account_fingerprint !== $tld->account_fingerprint
            || $account->registrar_key !== $tld->registrar_key || $value('domains_registrar') !== $tld->registrar_key
            || !in_array($action, ['register', 'transfer'], true) || $product->slug !== self::slug($tld, $action)
            || !$product->hidden || $product->allow_quantity !== 'separated'
            || ($display === null ? $this->unsafeLinks($product) : isset($display['unsafe'][$product->id]))) { throw new \DomainException('Invalid domain product mapping.'); }
        if ($plan) { $this->requirePlan($product, $plan, $action); }
        return ['tld' => $tld, 'action' => $action, 'term' => $plan?->billing_period];
    }

    private function requirePlan(Product $product, Plan $plan, string $action): void
    {
        if ($plan->priceable_type !== $product->getMorphClass() || (int) $plan->priceable_id !== (int) $product->id
            || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || $plan->billing_period < 1 || $plan->billing_period > 10
            || ($action === 'transfer' && $plan->billing_period !== 1)) { throw new \DomainException('Invalid domain plan mapping.'); }
    }

    /** Display snapshot only: strict mapping() above never trusts preloaded model relations. */
    public function display(Tld $tld): array
    {
        return $this->displayBatch([$tld])[$tld->id] ?? throw new \DomainException('Invalid domain product mapping.');
    }

    /** Batch only the selected public catalogue, including collisions by slug OR marker. Null isolates a corrupt ending. */
    public function displayBatch(array $tlds): array
    {
        if (!$tlds) { return []; }
        $tlds = collect($tlds)->keyBy('id'); $slugs = [];
        foreach ($tlds as $tld) {
            foreach (['register', 'transfer'] as $action) { $slugs[self::slug($tld, $action)] = $tld->id; }
        }
        $products = Product::where(static function ($q) use ($slugs, $tlds): void {
            $q->whereIn('slug', array_keys($slugs))->orWhereHas('settings', static fn ($s) => $s->where('key', 'domains_tld_id')->whereIn('value', $tlds->keys()->map(strval(...))));
        })->with(['settings' => static fn ($q) => $q->whereIn('key', self::KEYS),
            'plans.prices' => static fn ($q) => $q->where('currency_code', 'USD')])->get();
        $ids = $products->modelKeys(); $unsafe = [];
        foreach (DB::table('product_upgrades')->whereIn('product_id', $ids)->orWhereIn('upgrade_id', $ids)->get() as $link) {
            $unsafe[$link->product_id] = $unsafe[$link->upgrade_id] = true;
        }
        foreach (DB::table('config_option_products')->whereIn('product_id', $ids)->pluck('product_id') as $id) { $unsafe[$id] = true; }
        $display = ['tlds' => $tlds, 'servers' => Server::whereIn('id', $tlds->pluck('server_id')->unique())->get()->keyBy('id'),
            'accounts' => Account::whereIn('server_id', $tlds->pluck('server_id')->unique())->get()->keyBy('server_id'), 'unsafe' => $unsafe];
        $groups = [];
        foreach ($products as $product) {
            // A wrong/duplicate marker must poison its reserved slug as well as any marked ending.
            $targets = isset($slugs[$product->slug]) ? [$slugs[$product->slug]] : [];
            foreach ($product->settings->where('key', 'domains_tld_id') as $setting) {
                // Paymenter's retrieved event casts value by type; grouping must retain the database marker match.
                $rawId = $setting->getRawOriginal('value');
                if (is_string($rawId) && preg_match('/^[1-9][0-9]*$/D', $rawId) && $tlds->has((int) $rawId)) { $targets[] = (int) $rawId; }
            }
            foreach (array_unique($targets) as $id) { $groups[$id][] = $product; }
        }
        $rows = [];
        foreach ($tlds as $tld) {
            try {
                $actions = [];
                foreach ($groups[$tld->id] ?? [] as $product) {
                    $mapping = $this->mappingSettings($product, $product->settings, display: $display) ?? throw new \DomainException('Reserved domain slug collision.');
                    if ($mapping['tld']->id !== $tld->id) { throw new \DomainException('Domain product identity changed.'); }
                    $actions[$mapping['action']][] = $product;
                }
                $read = [];
                foreach ($actions as $action => $matches) {
                    if (count($matches) !== 1) { continue; }
                    $product = $matches[0]; $plans = [];
                    foreach ($product->plans->groupBy('billing_period') as $term => $matches) {
                        if ($matches->count() !== 1) { continue; }
                        $plan = $matches->first();
                        try { $this->requirePlan($product, $plan, $action); $plans[$term] = $plan; }
                        catch (\DomainException) { /* One invalid term does not remove healthy plans. */ }
                    }
                    $read[$action] = compact('product', 'plans');
                }
                $rows[$tld->id] = $read;
            } catch (\Throwable) { $rows[$tld->id] = null; }
        }
        return $rows;
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

    /** Reviewed publication through the active catalogue command. */
    public function publish(int $tldId, \DateTimeImmutable $now, ?StaffSettingsReview $review = null, ?\Closure $change = null): void
    {
        if ($change !== null) { throw new \DomainException('Typed catalogue command required.'); }
        ($this->catalogue() ?? throw new \DomainException(OfferUpgrade::MESSAGE))->publish($tldId,$review);
    }

}
