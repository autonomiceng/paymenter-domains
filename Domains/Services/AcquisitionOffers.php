<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts, Context, Cost, OfferState, Pricing, Query, Retail, Revision, UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Contracts\{AcquisitionPriceReader, Clock};
use Paymenter\Extensions\Servers\Domains\Data\{Action, Query as OrderQuery, RequestBudget, RequestContext, RequestOrigin, SaleSelection};
use Paymenter\Extensions\Servers\Domains\Models\{Account, SalesPolicy, Tld};

/** Shared commerce authority. Public prices come only from the daily table; checkout can re-quote.
 * Native checkout owns ALL native locks before admit(); the reader takes the whole catalogue prefix. */
final class AcquisitionOffers
{
    public function __construct(private readonly CallerPorts $ports, private readonly AccountBindings $bindings,
        private readonly NativeCatalogue $native) {}

    public function selection(string $ending, string $action, int $term): SaleSelection
    { return $this->offers([[$ending, $action, $term]])[0]['selection']; }

    public function offer(string $ending, string $action, int $term): array
    { return $this->offers([[$ending, $action, $term]])[0]; }

    /** Checkout alone may repair stale prices. Public/search offers remain daily-table only. */
    public function checkoutSelection(string $name, string $ending, int $term, ?SaleSelection $previous, CheckoutRefreshBudget $batch, int $serverId, string $fingerprint): SaleSelection
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Order quotes require unlocked reads.'); }
        if (isset($previous?->catalogue['order_quote'])) {
            try {
                $quote = $this->orderQuote($previous);
                if ($quote['name'] !== $name) { throw new \DomainException('Order quote name changed.'); }
                $this->validate($previous); return $previous;
            } catch (\DomainException) { /* Expired or changed local evidence needs a new quote. */ }
        }
        $policy = SalesPolicy::where('tld', $ending)->sole();
        $account = Account::where('server_id', $policy->server_id)->sole(); $b = AccountBindings::binding($account);
        if ($b->serverId !== $serverId || $b->fingerprint !== $fingerprint
            || $policy->account_fingerprint !== $b->fingerprint || $policy->registrar_key !== $b->registrarKey) { throw new \DomainException('Seller changed.'); }
        $seed = new SaleSelection($ending, $b->serverId, $b->registrarKey, $b->fingerprint, $policy->revision, 'register', $term, 'USD', 0, 0);
        $rows = $this->ports->loadBatch([new Query($b, [$ending], 'register', $term), new Query($b, [$ending], 'renew')], Context::Staff);
        [$acquire, $renew] = [$rows[0][0], $rows[1][0]];
        if ($acquire->decision->reason !== 'stale_price') { return $this->selection($ending, 'register', $term); }
        $tld = Tld::where(['server_id' => $b->serverId, 'tld' => $ending])->sole();
        $rule = Pricing::rule($tld);
        $stamp = ['binding' => [$b->serverId, $b->registrarKey, $b->fingerprint, $ending, $term],
            'generation' => UpgradeGate::generation(), 'credential' => $this->bindings->offerCredentialRevision($b),
            'revision' => get_object_vars($acquire->decision->revision), 'prices' => [$acquire->identity(), $renew->identity()], 'rule' => $rule];
        $clock = app(Clock::class);
        $costs = $retail = []; $driver = null;
        foreach ([$acquire, $renew] as $row) {
            $cost = $row->observation();
            if (!$cost?->enabled) { throw new \DomainException('Order price unavailable.'); }
            if ($cost->fresh($clock->now())) { continue; }
            // A successful first quote survives a pacing deferral on the second read.
            $key = 'domains.order-price.'.hash('sha256', OfferState::json([$name, $stamp, $cost->key()]));
            $saved = Cache::get($key);
            $fresh = is_array($saved) ? OfferState::cost($saved) : null;
            if (!$fresh?->fresh($clock->now())) {
                $driver ??= $this->bindings->reader($b->serverId); $b->requireMatch($driver->binding());
                $q = new OrderQuery($name,
                    Action::from($cost->action), $cost->term);
                $deadline = $clock->now()->modify('+'.min(10000, $batch->remainingMilliseconds()).' milliseconds');
                $context = new RequestContext($b,
                    RequestOrigin::Interactive, $deadline,
                    new RequestBudget(3), $clock, Runtime::limiter());
                $result = $driver instanceof AcquisitionPriceReader ? $driver->acquisitionQuote($q, $context) : $driver->quote($q, $context);
                $batch->remainingMilliseconds();
                $rate = CatalogueMapper::quote($result, $b, $q, $ending, $clock->now());
                if ($clock->now() >= $deadline) { throw new CheckoutRefreshTimeout; }
                $until = min($rate->freshUntil ?? $clock->now()->modify('+5 minutes'), $clock->now()->modify('+5 minutes'));
                $evidence = $result->evidence()->quote;
                $fresh = new Cost($cost->action, $cost->term, true, $evidence->cost->amount,
                    $evidence->unit, $rate->source, $rate->observedAt, $until);
                Cache::put($key, OfferState::encodeCost($fresh), 300);
            }
            $costs[$cost->key()] = OfferState::encodeCost($fresh);
            $retail[$cost->key()] = Retail::quote($fresh, $rule);
        }
        $token = bin2hex(random_bytes(32));
        Cache::put('domains.order-quote.'.$token, [...$stamp, 'name' => $name, 'costs' => $costs, 'retail' => $retail,
            'native_retail' => $acquire->staffRetail()], 300);
        $seed = new SaleSelection(...[...get_object_vars($seed), 'catalogue' => ['order_quote' => $token]]);
        return $this->load([$seed], false, false)[0]['selection'];
    }

    /** Pending invoices may outlive their cart quote. Recheck the same sale without enlarging
     * frozen retail or spending authority, then use that current selection for the pay guard. */
    public function paymentSelection(SaleSelection $frozen, string $name, string $ceiling, string $total): SaleSelection
    {
        $current = $this->checkoutSelection($name, $frozen->tld, $frozen->term, $frozen,
            new CheckoutRefreshBudget, $frozen->serverId, $frozen->fingerprint);
        $old = get_object_vars($frozen); $new = get_object_vars($current);
        unset($old['catalogue']['order_quote'], $new['catalogue']['order_quote']);
        $price = $this->load([$current], false, true)[0]['snapshot'];
        if ($old !== $new || !PricingRule::covers($total, $price->wholesale)
            || PricingRule::units($price->wholesale) > PricingRule::units($ceiling)) {
            throw new \DomainException('Frozen order price or spending ceiling changed.');
        }
        return $current;
    }

    /** Native list row still has to match its original publication, even for a cart-only quote. */
    public function listedRetail(SaleSelection $s): string
    { return isset($s->catalogue['order_quote']) ? $this->orderQuote($s)['native_retail'] : $s->catalogue['retail']; }

    private function orderQuote(SaleSelection $s): array
    {
        $token = $s->catalogue['order_quote'] ?? '';
        $quote = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? Cache::get('domains.order-quote.'.$token) : null;
        if (!is_array($quote) || $quote['binding'] !== [$s->serverId, $s->registrar, $s->fingerprint, $s->tld, $s->term]) {
            throw new \DomainException('Order quote expired or changed.');
        }
        return $quote;
    }

    public function transfersOn(string $ending): bool
    { try { $this->offer($ending, 'transfer', 1); return true; } catch (\DomainException) { return false; } }

    /** Public batches resolve ONLY the selected account, never a cheaper candidate. */
    public function offers(array $requests): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Public offers require unlocked reads.'); }
        $selections = [];
        foreach ($requests as [$ending, $action, $term]) {
            $policy = SalesPolicy::where('tld', $ending)->sole();
            $account = Account::where('server_id', $policy->server_id)->sole();
            $b = AccountBindings::binding($account);
            if ($b->fingerprint !== $policy->account_fingerprint || $b->registrarKey !== $policy->registrar_key) { throw new \DomainException('Seller changed.'); }
            $selections[] = new SaleSelection($ending, $b->serverId, $b->registrarKey, $b->fingerprint,
                $policy->revision, $action, $term, 'USD', 0, 0);
        }
        $batch = $this->load($selections, false, false); $result = [];
        foreach ($batch as $i => $row) {
            $s = $row['selection']; $tld = Tld::where(['server_id' => $s->serverId, 'tld' => $s->tld])->sole();
            $product = $this->native->product($tld, $s->action);
            if (!$product || $product->stock !== null) { throw new \DomainException('Offer unavailable.'); }
            $plan = $product->plans()->where('billing_period', $s->term)->sole(); $this->native->mapping($product, $plan);
            $native = $plan->prices()->where('currency_code', 'USD')->sole();
            if (RetailLine::decimal($native->price) !== $row['snapshot']->retail || RetailLine::decimal($native->setup_fee) !== '0.00') {
                throw new \DomainException('Native offer changed.');
            }
            $result[] = ['selection' => $s, 'product' => $product, 'plan' => $plan,
                'price' => Storefront::money($row['snapshot']->retail), 'renew' => Storefront::money($row['renewal']->retail)];
        }
        return $result;
    }

    public function admit(array $selections): array
    {
        if (DB::transactionLevel() === 0) { throw new \LogicException('Admission requires native locks.'); }
        return $this->load($selections, true, true);
    }

    /** Receipt reuse and post-provider reads validate locally, without another provider call. */
    public function validate(SaleSelection $selection, bool $locked = false, ?string $name = null): void
    {
        if ($name !== null && isset($selection->catalogue['order_quote']) && $this->orderQuote($selection)['name'] !== $name) {
            throw new \DomainException('Order quote name changed.');
        }
        $this->load([$selection], $locked, true);
    }

    /** Per-invocation display only. Shared evaluator and batched native mapping, never cart authority. */
    public function display(array $endings): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Display requires unlocked reads.'); }
        $policies = SalesPolicy::whereIn('tld', $endings)->get();
        $ids = $policies->pluck('server_id')->filter()->unique();
        $accounts = Account::whereIn('server_id', $ids)->get()->keyBy('server_id');
        $tlds = Tld::whereIn('server_id', $ids)->whereIn('tld', $endings)->get()->keyBy(static fn ($t) => $t->server_id.':'.$t->tld);
        $native = $this->native->displayBatch($tlds->all()); $generation = UpgradeGate::generation();
        $result = [];
        foreach ($policies->groupBy('server_id') as $id => $group) {
            try {
                $b = AccountBindings::binding($accounts[$id]);
                $credential = $this->bindings->offerCredentialRevision($b);
                foreach (array_chunk($group->pluck('tld')->all(), 500) as $names) {
                    foreach ($this->ports->displayBatch([new Query($b, $names, 'register')])[0] as $name => $rows) {
                        $tld = $tlds[$id.':'.$name] ?? null;
                        foreach ($rows as $key => $row) {
                            if ($key === 'renew:1') { continue; }
                            try {
                                $price = $row->price(); $annual = $rows['renew:1']->price();
                                $action = $price->action; $term = $price->term;
                                $product = $native[$tld->id][$action]['product'] ?? null;
                                $plan = $native[$tld->id][$action]['plans'][$term] ?? null;
                                if (!$product || !$plan || $product->stock !== null) { continue; }
                                $usd = $plan->prices->where('currency_code', 'USD')->sole();
                                if (RetailLine::decimal($usd->price) !== $price->retail || RetailLine::decimal($usd->setup_fee) !== '0.00') { continue; }
                                $revision = $row->decision->revision;
                                $s = new SaleSelection($name, $b->serverId, $b->registrarKey, $b->fingerprint,
                                    $revision->selection, $action, $term, 'USD', $price->id, $price->revision,
                                    ['generation' => $generation, 'credential' => $credential, 'revision' => get_object_vars($revision),
                                        'retail' => $price->retail, 'renewal' => $annual->retail]);
                                $result[$name][$key] = ['selection' => $s, 'product' => $product, 'plan' => $plan,
                                    'price' => Storefront::money($price->retail), 'renew' => Storefront::money($annual->retail)];
                            } catch (\Throwable) { /* This ending/term is unavailable; keep healthy siblings. */ }
                        }
                    }
                }
            } catch (\Throwable) { /* Missing/disabled/invalid account withdraws only its own endings. */ }
        }
        return $result;
    }

    private function load(array $selections, bool $locked, bool $expected): array
    {
        if (!$selections || count($selections) > 250) { throw new \DomainException('Invalid acquisition batch.'); }
        $queries = []; $bindings = [];
        foreach ($selections as $s) {
            $a = Account::where('server_id', $s->serverId)->sole(); $b = AccountBindings::binding($a);
            if ($b->fingerprint !== $s->fingerprint || $b->registrarKey !== $s->registrar) { throw new \DomainException('Offer binding changed.'); }
            $bindings[] = $b;
            $revision = $expected ? new Revision(...($s->catalogue['revision'] ?? throw new \DomainException('Refresh the domain offer.'))) : null;
            foreach ([[$s->action, $s->term], ['renew', 1]] as [$action, $term]) {
                $queries[] = new Query($b, [$s->tld], $action, $term, $s->currency, $revision ? [$s->tld => $revision] : []);
            }
        }
        $loaded = $this->ports->loadBatch($queries, $locked ? Context::Locked : Context::Public);
        // All servers/accounts are now locked in batch order, including other cart accounts.
        $generation = UpgradeGate::generation(); $result = [];
        foreach ($selections as $i => $s) {
            $acquire = $loaded[2*$i][0]; $renew = $loaded[2*$i+1][0];
            $quote = isset($s->catalogue['order_quote']) ? $this->orderQuote($s) : null;
            if ($quote !== null) {
                $tld = Tld::where(['server_id' => $s->serverId, 'tld' => $s->tld])->sole();
                if ($quote['generation'] !== $generation || $quote['credential'] !== $this->bindings->offerCredentialRevision($bindings[$i])
                    || $quote['revision'] !== get_object_vars($acquire->decision->revision)
                    || $quote['prices'] !== [$acquire->identity(), $renew->identity()]
                    || $quote['rule'] !== Pricing::rule($tld)) {
                    throw new \DomainException('Order quote authority changed.');
                }
                $costs = array_map(OfferState::cost(...), $quote['costs']);
                $now = app(Clock::class)->now();
                $acquire = $acquire->forOrder($costs, $quote['retail'], $now);
                $renew = $renew->forOrder($costs, $quote['retail'], $now);
            }
            $price = $acquire->price(); $annual = $renew->price();
            $fence = ['generation' => $generation, 'credential' => $this->bindings->offerCredentialRevision($bindings[$i]),
                'revision' => get_object_vars($acquire->decision->revision), 'retail' => $price->retail,
                'renewal' => $annual->retail, ...($quote === null ? [] : ['order_quote' => $s->catalogue['order_quote']])];
            if ($expected && ($s->catalogue !== $fence || $s->priceId !== $price->id || $s->priceRevision !== $price->revision
                || $s->policyRevision !== $acquire->decision->revision->selection)) {
                throw new \DomainException('Refresh the domain offer.');
            }
            $selected = new SaleSelection($s->tld, $s->serverId, $s->registrar, $s->fingerprint,
                $acquire->decision->revision->selection, $s->action, $s->term, $s->currency, $price->id, $price->revision, $fence);
            $result[] = ['selection' => $selected, 'snapshot' => $price, 'renewal' => $annual];
        }
        return $result;
    }

    /** Contact validation consumes the independently observed registry schema, never old TLD facts.
     * A transient copy keeps native contact/tax/owner validation intact without a legacy write. */
    public function contactTld(Tld $tld): Tld
    {
        $facts = OfferState::facts($tld->catalogue_facts);
        if (!$tld->catalogue_converted || !$facts || !RegistryInputs::supported($tld->registrar_key, $tld->tld, $facts->registryFields)) {
            throw new \DomainException('Current registry details unavailable.');
        }
        $copy = clone $tld; $copy->registry_fields = $facts->registryFields;
        return $copy;
    }

    /** Writer commits advance the shared display revision (including pending publication/facts).
     * Hits read account state only; raw unrevisioned offer maintenance retains the display TTL bound.
     * Encrypted credential/enrollment/settings bytes fence rotation even when the account is unavailable. */
    public function cacheFence(): string
    {
        $ids = SalesPolicy::distinct()->pluck('server_id')->filter()->all();
        $rows = []; $servers = (new \App\Models\Server)->getTable();
        foreach (['domains_accounts', $servers, 'settings'] as $table) {
            $query = DB::table($table);
            if ($table === 'settings') { $query->where('settingable_type', (new \App\Models\Server)->getMorphClass())->whereIn('settingable_id', $ids); }
            else { $query->whereIn($table === $servers ? 'id' : 'server_id', $ids); }
            $rows[] = $query->orderBy('id')->get()->map(static fn ($r) => (array)$r)->all();
        }
        return hash('sha256', OfferState::json([UpgradeGate::generation(), $rows]));
    }
}
