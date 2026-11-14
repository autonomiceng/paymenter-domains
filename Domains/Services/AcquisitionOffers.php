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

    /** Local reads only: true when staff took this account's sale of the ending off sale. */
    public function offSale(int $serverId, string $ending, string $action, int $term): bool
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Sale checks require unlocked reads.'); }
        $b = AccountBindings::binding(Account::where('server_id', $serverId)->sole());
        return $this->ports->loadBatch([new Query($b, [$ending], $action, $term)], Context::Public)[0][0]->closed();
    }

    /** Checkout alone may repair stale prices. Public/search offers remain daily-table only. */
    public function checkoutSelection(string $name, string $ending, string $action, int $term, ?SaleSelection $previous, CheckoutRefreshBudget $batch, int $serverId, string $fingerprint): SaleSelection
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Order quotes require unlocked reads.'); }
        if (!in_array($action, ['register', 'transfer'], true) || $action === 'transfer' && $term !== 1) { throw new \DomainException('Acquisition term unavailable.'); }
        if (isset($previous?->catalogue['order_quote'])) {
            try {
                $quote = $this->orderQuote($previous);
                if ($quote['name'] !== $name || $previous->action !== $action) { throw new \DomainException('Order quote name changed.'); }
                $this->validate($previous); return $previous;
            } catch (\DomainException) { /* Expired or changed local evidence needs a new quote. */ }
        }
        $policy = SalesPolicy::where('tld', $ending)->sole();
        $account = Account::where('server_id', $policy->server_id)->sole(); $b = AccountBindings::binding($account);
        if ($b->serverId !== $serverId || $b->fingerprint !== $fingerprint
            || $policy->account_fingerprint !== $b->fingerprint || $policy->registrar_key !== $b->registrarKey) { throw new \DomainException('Seller changed.'); }
        $seed = new SaleSelection($ending, $b->serverId, $b->registrarKey, $b->fingerprint, $policy->revision, $action, $term, 'USD', 0, 0);
        $rows = $this->ports->loadBatch([new Query($b, [$ending], $action, $term), new Query($b, [$ending], 'renew')], Context::Public);
        [$acquire, $renew] = [$rows[0][0], $rows[1][0]];
        $observations = $acquire->pricingInputs();

        $clock = app(Clock::class);
        $current = true;
        foreach ($observations as $cost) { if (!$cost?->fresh($clock->now())) { $current = false; } }
        if ($current) {
            return $this->selection($ending, $action, $term);
        }
        $tld = Tld::where(['server_id' => $b->serverId, 'tld' => $ending])->sole();
        $rule = Pricing::rule($tld);
        $stamp = ['binding' => [$b->serverId, $b->registrarKey, $b->fingerprint, $ending, $action, $term],
            'generation' => UpgradeGate::generation(), 'credential' => $this->bindings->offerCredentialRevision($b),
            'revision' => get_object_vars($acquire->decision->revision), 'prices' => [$acquire->identity(), $renew->identity()], 'rule' => $rule];
        $costs = $retail = []; $driver = null;
        try {
            foreach ($observations as $cost) {
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
                $observations[$cost->key()] = $fresh;
            }
            foreach ($observations as $key => $cost) { $retail[$key] = Retail::quote($cost, $rule, $observations); }
            $token = bin2hex(random_bytes(32));
            Cache::put('domains.order-quote.'.$token, [...$stamp, 'name' => $name, 'costs' => $costs, 'retail' => $retail,
                'native_retail' => $acquire->listedRetail()], 300);
            $seed = new SaleSelection(...[...get_object_vars($seed), 'catalogue' => ['order_quote' => $token]]);
            return $this->load([$seed], false, false)[0]['selection'];
        }
        catch (\DomainException $e) {
            $quoted = $costs[$acquire->observation()?->key()] ?? null;
            throw $quoted === null ? $e : new OrderQuoteRefused($e, OfferState::cost($quoted));
        }
    }

    /** Pending invoices outlive cart quotes and staff saves. Recheck the same sale against the current offer:
     * same seller, account, action, term and currency, still sold now, and the current cost within both the
     * issued total and the frozen spending ceiling. Revisions and retail may move; the issued amount stands. */
    public function paymentSelection(SaleSelection $frozen, string $name, string $ceiling, string $total): SaleSelection
    {
        try {
            $current = $this->checkoutSelection($name, $frozen->tld, $frozen->action, $frozen->term, $frozen,
                new CheckoutRefreshBudget, $frozen->serverId, $frozen->fingerprint);
        } catch (\DomainException $e) {
            $this->costRefusal($frozen, $name, $ceiling, $total, $e instanceof OrderQuoteRefused ? $e->cost : null); throw $e;
        }
        $sale = static fn (SaleSelection $s) => [$s->tld, $s->serverId, $s->registrar, $s->fingerprint, $s->action, $s->term,
            $s->currency, $s->catalogue['generation'] ?? null, $s->catalogue['credential'] ?? null];
        if ($sale($frozen) !== $sale($current)) { throw new \DomainException('Seller or account changed.'); }
        $price = $this->load([$current], false, true)[0]['snapshot'];
        if (!self::fits($price->wholesale, $ceiling, $total)) { throw new PaymentPriceChanged($name); }
        return $current;
    }

    private static function fits(string $cost, string $ceiling, string $total): bool
    { return PricingRule::covers($total, $cost) && PricingRule::units($cost) <= PricingRule::units($ceiling); }

    /** A refused current offer whose fresh cost (daily table, or this request's refreshed quote) no longer fits
     * the invoice is a price change (a fixed retail below the new cost, for example), not a temporary stop.
     * Staff or seller stops keep their own refusal. */
    private function costRefusal(SaleSelection $s, string $name, string $ceiling, string $total, ?Cost $quoted = null): void
    {
        try {
            $b = AccountBindings::binding(Account::where('server_id', $s->serverId)->sole());
            if ($b->fingerprint !== $s->fingerprint || $b->registrarKey !== $s->registrar) { return; }
            $row = $this->ports->loadBatch([new Query($b, [$s->tld], $s->action, $s->term)], Context::Public)[0][0];
            $cost = $row->closed() ? null : ($quoted ?? $row->observation());
        } catch (\Throwable) { return; }
        if ($cost?->enabled && $cost->total !== null && $cost->fresh(app(Clock::class)->now()) && !self::fits($cost->total, $ceiling, $total)) {
            throw new PaymentPriceChanged($name);
        }
    }

    /** Native list row still has to match its original publication, even for a cart-only quote. */
    public function listedRetail(SaleSelection $s): string
    { return isset($s->catalogue['order_quote']) ? $this->orderQuote($s)['native_retail'] : $s->catalogue['retail']; }

    private function orderQuote(SaleSelection $s): array
    {
        $token = $s->catalogue['order_quote'] ?? '';
        $quote = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? Cache::get('domains.order-quote.'.$token) : null;
        if (!is_array($quote) || $quote['binding'] !== [$s->serverId, $s->registrar, $s->fingerprint, $s->tld, $s->action, $s->term]) {
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
        $generation = UpgradeGate::generation(); $result = [];
        foreach ($this->eligibleRows($endings) as $offer) {
            extract($offer);
            if (($mismatch = self::nativePriceMismatch($product, $plan, $price->retail)) !== null) {
                try {
                    $day = app(Clock::class)->now()->format('Y-m-d');
                    if (Cache::add('domains.price-mismatch.'.$name.'.'.$price->action.'.'.$price->term.'.'.$day, true, 86400)) {
                        logger()->warning('Domain acquisition price mismatch; term hidden.',
                            ['tld' => $name, 'action' => $price->action, 'term' => $price->term,
                                'catalogue' => $price->retail, 'plan' => $mismatch]);
                    }
                } catch (\Throwable) { /* Diagnostics must not withdraw healthy sibling terms. */ }
                continue;
            }
            $revision = $row->decision->revision;
            $s = new SaleSelection($name, $b->serverId, $b->registrarKey, $b->fingerprint,
                $revision->selection, $price->action, $price->term, 'USD', $price->id, $price->revision,
                ['generation' => $generation, 'credential' => $credential, 'revision' => get_object_vars($revision),
                    'retail' => $price->retail, 'renewal' => $annual->retail]);
            $result[$name][$key] = ['selection' => $s, 'product' => $product, 'plan' => $plan,
                'price' => Storefront::money($price->retail), 'renew' => Storefront::money($annual->retail)];
        }
        return $result;
    }

    /** Catalogue eligibility BEFORE native filtering. Local reads only; no warning/cache writes. */
    public function priceMatches(?array $endings = null, ?int $serverId = null): array
    {
        $result = [];
        foreach ($this->eligibleRows($endings ?? SalesPolicy::orderBy('tld')->pluck('tld')->all(), $serverId) as $offer) {
            $price = $offer['price'];
            $result[] = ['tld' => $offer['name'], 'action' => $price->action, 'term' => $price->term,
                'catalogue' => $price->retail, 'plan' => $offer['plan']?->id,
                'mismatch' => self::nativePriceMismatch($offer['product'], $offer['plan'], $price->retail)];
        }
        return $result;
    }

    /** After a feed/publication turn, check only its selected endings; one warning per mismatch. */
    public function warnPriceMismatches(array $endings, int $serverId): void
    {
        foreach ($this->priceMatches($endings, $serverId) as $row) {
            if ($row['mismatch'] !== null) { logger()->warning('Domain acquisition price mismatch after catalogue publication.', $row); }
        }
    }

    private static function nativePriceMismatch(?\App\Models\Product $product, ?\App\Models\Plan $plan, string $retail): ?string
    {
        if (!$product || !$plan) { return 'Missing, duplicate or invalid native product/annual plan.'; }
        if ($product->stock !== null) { return 'Native product has limited stock.'; }
        $prices = $plan->prices->where('currency_code', 'USD');
        if ($prices->count() !== 1) { return 'Expected one USD price; found '.$prices->count().'.'; }
        try {
            $usd = $prices->sole(); $amount = RetailLine::decimal($usd->price); $setup = RetailLine::decimal($usd->setup_fee);
            return $amount === $retail && $setup === '0.00' ? null : '$'.$amount.'; setup $'.$setup.'.';
        } catch (\DomainException) { return 'Invalid native USD amount or setup fee.'; }
    }

    /** Evaluate every catalogue-supported acquisition term under the current public gates. */
    private function eligibleRows(array $endings, ?int $serverId = null): \Generator
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Display requires unlocked reads.'); }
        $policies = SalesPolicy::whereIn('tld', $endings)->when($serverId !== null, static fn ($q) => $q->where('server_id', $serverId))->get();
        $ids = $policies->pluck('server_id')->filter()->unique();
        $accounts = Account::whereIn('server_id', $ids)->get()->keyBy('server_id');
        $tlds = Tld::whereIn('server_id', $ids)->whereIn('tld', $endings)->get()->keyBy(static fn ($t) => $t->server_id.':'.$t->tld);
        $native = $this->native->displayBatch($tlds->all());
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
                                yield compact('name', 'key', 'b', 'credential', 'row', 'price', 'annual', 'product', 'plan');
                            } catch (\Throwable) { /* This ending/term is unavailable; keep healthy siblings. */ }
                        }
                    }
                }
            } catch (\Throwable) { /* Missing/disabled/invalid account withdraws only its own endings. */ }
        }
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
