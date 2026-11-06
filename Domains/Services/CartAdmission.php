<?php
// Adapted from Enom 444f31c Services/CartOrdering.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Classes\Cart as NativeCart;
use App\Models\{CartItem, Order, Product, Service};
use Illuminate\Support\Facades\{Auth, DB, Event};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, SaleSelection};

/** One batch in native Order Creating, after ALL native user/coupon/product locks. Dormant until slice 39. */
class CartAdmission
{
    /** Fixed legacy route. No production composition selects the dormant adapter. */
    protected function catalogue(): ?AcquisitionOffers
    { return \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(AcquisitionOffers::class); }

    private ?Order $order = null;
    private array $lines = [];
    public function __construct(private readonly NativeCatalogue $native, private readonly SellerPolicies $sellers,
        private readonly CheckoutEligibility $eligibility, private readonly Clock $clock) {}

    public function boot(): void
    {
        if(!HookRegistration::claim(Event::getFacadeRoot(),self::class)){return;}
        CartCheckoutRefresh::boot($this);
        foreach ([\App\Events\CartItem\Creating::class, \App\Events\CartItem\Updating::class] as $event) {
            Event::listen($event, function ($e): void {
                try { $this->prepare($e->cartItem); }
                catch (\DomainException|\InvalidArgumentException $error) {
                    if (!(\Livewire\Livewire::current() instanceof \App\Livewire\Products\Checkout)) { throw $error; }
                    throw new \App\Exceptions\DisplayException('Your domain offer changed or expired. Search again for a current offer.');
                }
            });
        }
        Event::listen(\App\Events\Order\Creating::class,function($e):void {
            try { $this->admit($e->order); }
            catch (\DomainException|\InvalidArgumentException $error) {
                $message = $error instanceof CheckoutCheckExpired || $error->getMessage() === NativeUserDeletion::PENDING
                    ? $error->getMessage()
                    : 'Your domain offer changed or expired. Remove this line from your cart and search again for a current offer.';
                throw new \App\Exceptions\DisplayException($message);
            }
        });
        foreach ([\Illuminate\Database\Events\TransactionCommitted::class, \Illuminate\Database\Events\TransactionRolledBack::class] as $event) {
            Event::listen($event, function ($e): void {
                if ($e->connection === DB::connection() && $e->connection->transactionLevel() === 0) { $this->order = null; $this->lines = []; }
            });
        }
    }

    /** Native checkout schema only; contacts/registry inputs are supplied by slice 18. No network on rendering. */
    public function fields(Product $product): array
    {
        $mapping = $this->native->mapping($product);
        return !$mapping ? [] : [['name' => 'domain', 'type' => 'text', 'label' => 'Domain name', 'required' => true,
            'placeholder' => 'example.'.$mapping['tld']->tld,
            'description' => $mapping['action'] === 'transfer' ? 'Enter the auth code after checkout.' : 'Enter the full domain name.']];
    }

    public function prepare(CartItem $item): void
    {
        $product = Product::findOrFail($item->product_id);
        if (!$this->native->mapping($product)) { return; }
        $mapping = $this->validate($item, $product);
        $name = $this->name($item, $mapping);
        foreach ($item->cart->items()->where('id', '!=', $item->id ?? 0)->get() as $other) {
            if (($other->checkout_config['domain'] ?? null) === $name && $this->native->mapping($other->product)) { throw new \DomainException('Domain already in cart.'); }
        }
        $selected = $this->catalogue()?->selection($mapping['tld']->tld, $mapping['action'], $mapping['term'])
            ?? $this->sellers->selection($mapping['tld']->tld, $mapping['action'], $mapping['term'], $this->clock->now());
        if ($selected->serverId !== (int) $product->server_id || $selected->fingerprint !== $mapping['tld']->account_fingerprint) {
            throw new \DomainException('Registrar changed. Search again.');
        }
        $token = $this->eligibility->observe($name, $selected, $this->identity($item));
        // Drop arbitrary fields, including transfer codes and client-supplied authority claims.
        $item->checkout_config = ['domain' => $name, 'domains_check' => $token];
    }

    /** Before native mount/checkout only. Quiet CAS avoids update hooks and stale-tab overwrites.
     * Lines staff took off sale are removed and reported with CartLinesRemoved before any other line refreshes. */
    public function refresh(): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Refresh requires an unlocked cart.'); }
        $cart = NativeCart::get();
        if (!$cart->exists || ($cart->user_id !== null && (int) $cart->user_id !== (int) Auth::id())) { return; }
        $batch = new CheckoutRefreshBudget; $removed = []; $lines = [];
        // Local removal pass first, so a later refresh failure cannot swallow its one-time notice.
        foreach ($cart->items as $item) {
            $product = Product::findOrFail($item->product_id);
            $mapping = $this->native->mapping($product, $item->plan);
            if (!$mapping || !in_array($mapping['action'], ['register', 'transfer'], true)) { continue; }
            if ($this->catalogue()?->offSale((int) $product->server_id, $mapping['tld']->tld, $mapping['action'], $mapping['term'])) {
                $name = $this->name($item, $mapping);
                $removed[$item->id] = [$name, $item->product_id, $item->plan_id]; continue;
            }
            $lines[] = [$item, $product, $mapping];
        }
        if ($removed) {
            $removed = DB::transaction(function () use ($cart, $removed): array {
                $query = CartItem::where('cart_id', $cart->id)->where(function ($q) use ($removed): void {
                    foreach ($removed as $id => [$name, $product, $plan]) {
                        $q->orWhere(fn ($q) => $q->whereKey($id)->where('product_id', $product)->where('plan_id', $plan));
                    }
                });
                // Lock the matched identities so the single delete and its notice cover exactly those rows.
                $ids = (clone $query)->lockForUpdate()->pluck('id')->all();
                if ($ids) { $query->whereIn('id', $ids)->delete(); }
                return array_map(static fn ($row) => $row[0], array_intersect_key($removed, array_flip($ids)));
            });
        }
        if ($removed) {
            // The native page and checkout read this same memoized cart. Checkout stops; the next click refreshes the rest.
            $cart->setRelation('items', $cart->items->reject(static fn ($item) => isset($removed[$item->id]))->values());
            throw new CartLinesRemoved(array_values($removed));
        }
        foreach ($lines as [$item, $product, $mapping]) {
            $batch->remainingMilliseconds();
            $this->validate($item, $product); $name = $this->name($item, $mapping);
            $config = $item->checkout_config;
            while (true) {
                try {
                    $offers = $this->catalogue();
                    $selected = $offers?->checkoutSelection($name, $mapping['tld']->tld, $mapping['action'], $mapping['term'],
                        $this->eligibility->previous((string) ($config['domains_check'] ?? ''), $name, $this->identity($item)), $batch,
                        (int) $product->server_id, $mapping['tld']->account_fingerprint)
                        ?? $this->sellers->selection($mapping['tld']->tld, $mapping['action'], $mapping['term'], $this->clock->now());
                    if ($selected->serverId !== (int) $product->server_id || $selected->fingerprint !== $mapping['tld']->account_fingerprint) {
                        throw new \DomainException('Registrar changed.');
                    }
                    $token = $this->eligibility->refresh((string) ($config['domains_check'] ?? ''), $name, $selected, $this->identity($item), $batch);
                    if (isset($selected->catalogue['order_quote'])) {
                        // Eager-loaded same-plan lines share models; isolate each transient quote.
                        $plan = clone $item->plan;
                        $plan->setRelation('prices', $plan->prices->map(static fn ($price) => clone $price));
                        $item->setRelation('plan', $plan);
                        // In-memory native plan only: native tax/coupon/invoice arithmetic stays intact.
                        $item->plan->prices->where('currency_code', 'USD')->sole()->price = $selected->catalogue['retail'];
                    }
                    break;
                }
                catch (ReadDeferred $e) {
                    $delay = $e->retryAfterMicros;
                    if ($delay === null) { throw $e; }
                    // The failed reservation transaction and coalescing lock have both unwound.
                    // Retry the full guarded read, never a physical call with the guard bypassed.
                    $batch->wait($delay);
                }
            }
            if (ComplimentaryAccounts::authorization((int) Auth::id())) {
                $item->plan->prices->where('currency_code','USD')->sole()->price='0.00';
                $item->offsetUnset('price'); // Native object accessor caches the prior priced line.
            }
            if ($token === ($config['domains_check'] ?? null)) { continue; }
            $item->checkout_config = [...$config, 'domains_check' => $token];
            $updated = CartItem::whereKey($item->id)->where('cart_id', $cart->id)->where('product_id', $item->product_id)
                ->where('plan_id', $item->plan_id)->where('checkout_config->domain', $config['domain'])
                ->where('checkout_config->domains_check', $config['domains_check'] ?? null)
                ->update(['checkout_config' => json_encode($item->checkout_config, JSON_THROW_ON_ERROR)]);
            if (!$updated) {
                $current = $cart->items()->whereKey($item->id)->first();
                if (!$current || (int) $current->product_id !== (int) $item->product_id || (int) $current->plan_id !== (int) $item->plan_id
                    || $current->checkout_config !== $item->checkout_config) { throw new \DomainException('Cart changed. Try checkout again.'); }
            }
        }
    }

    /** Signed page memo contains only the domain lines and retail the customer could see. */
    public function offers(): array
    {
        $offers = [];
        foreach (NativeCart::items() as $item) {
            $mapping = $this->native->mapping($item->product, $item->plan);
            if ($mapping && in_array($mapping['action'], ['register', 'transfer'], true)) {
                $offers[] = [$item->id, $this->identity($item), $this->name($item, $mapping),
                    RetailLine::decimal($item->plan->prices->where('currency_code', 'USD')->sole()->price)];
            }
        }
        return $offers;
    }

    /** Local contact schemas for every domain line, including transfers. No availability reads. */
    public function contactTlds(): array
    {
        $tlds = [];
        foreach (NativeCart::items() as $item) {
            $mapping = $this->native->mapping($item->product, $item->plan);
            if ($mapping) { $tlds[$mapping['tld']->id] = $this->catalogue()?->contactTld($mapping['tld']) ?? $mapping['tld']; }
        }
        return array_values($tlds);
    }

    private function admit(Order $order): void
    {
        $cart = NativeCart::get(); $lines = $selections = $names = []; $legacy = $this->catalogue() === null;
        // Use the SAME cached native items which checkout just locked; never reload a different batch here.
        foreach ($cart->items as $item) {
            $product = Product::findOrFail($item->product_id);
            if (!$this->native->mapping($product)) { continue; }
            $account = \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $product->server_id)->firstOrFail();
            $binding = AccountBindings::binding($account);
            if ($legacy && EnomTestRegistration::checkoutPreviewOnly($binding) && !EnomTestRegistration::sellable($binding) && !(($this->native->mapping($product)['action']??null)==='transfer'&&EnomTestTransfer::accepted($binding))) {
                throw new \App\Exceptions\DisplayException($binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?'Domains can’t be ordered right now. Remove them to check out.':'Test domains can’t be ordered. Remove them to check out.');
            }
            if (DB::transactionLevel() !== 1 || $this->order || (int) Auth::id() !== (int) $order->user_id
                || ($cart->user_id !== null && (int) $cart->user_id !== (int) $order->user_id) || $order->currency_code !== 'USD') {
                throw new \DomainException('Domain checkout requires its native transaction and owner.');
            }
            $mapping = $this->validate($item, $product);
            if ($legacy && $mapping['action'] !== 'register' && EnomTestRegistration::sellable($binding) && !EnomTestTransfer::accepted($binding)) {
                throw new \App\Exceptions\DisplayException($binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?'Domains can’t be ordered right now. Remove them to check out.':'Test transfers can’t be ordered yet. Remove them to check out.');
            }
            $name = $this->name($item, $mapping);
            if (isset($names[$name])) { throw new \DomainException('Duplicate domain cart line.'); } $names[$name] = true;
            $selection = $this->requireCheck((string) ($item->checkout_config['domains_check'] ?? ''), $name, $this->identity($item), $mapping['action']);
            if ($selection->serverId !== (int) $product->server_id || $selection->fingerprint !== $mapping['tld']->account_fingerprint
                || $selection->tld !== $mapping['tld']->tld || $selection->action !== $mapping['action'] || $selection->term !== $mapping['term']) {
                throw new \DomainException('Domain check does not match this product.');
            }
            $complimentary=ComplimentaryAccounts::authorization((int)$order->user_id);
            $retail = $complimentary ? ['total'=>'0.00','tax'=>'0.00','discount'=>'0.00','net'=>'0.00'] : RetailLine::native($item->price);
            if($complimentary && RetailLine::decimal($item->price->total)!=='0.00'){throw new \DomainException('Refresh complimentary checkout.');}
            $selections[] = $selection;
            $lines[] = ['item_id' => $item->id, 'product_id' => $product->id, 'plan_id' => $item->plan_id, 'name' => $name,
                'mapping' => $mapping, 'retail' => $retail, 'selection' => $selection, 'complimentary'=>$complimentary,
                'admitted_user_id' => (int) $order->user_id, 'check' => $item->checkout_config['domains_check'], 'identity' => $this->identity($item)];
        }
        if (!$lines) { return; }
        // Native checkout already locked this user before coupons and products.
        NativeUserDeletion::requireOwner((int)$order->user_id);
        // Exactly one call for the whole checkout. Any exception aborts the native outer transaction.
        $offers = $this->catalogue();
        $batch = $offers?->admit($selections);
        $snapshots = $batch === null ? $this->sellers->admit($selections, $this->clock->now()) : array_column($batch, 'snapshot');
        foreach ($lines as $index => &$line) {
            $snapshot = $snapshots[$index]; $item = $cart->items->firstWhere('id', $line['item_id']);
            $price = $item->plan()->firstOrFail()->prices()->where('currency_code', 'USD')->sole();
            $cached = $item->plan->prices->where('currency_code', 'USD')->sole();
            $listed = $offers?->listedRetail($line['selection']) ?? $snapshot->retail;
            if (RetailLine::decimal($price->price) !== $listed || RetailLine::decimal($price->setup_fee) !== '0.00'
                || RetailLine::decimal($cached->price) !== ($line['complimentary']?'0.00':$snapshot->retail) || RetailLine::decimal($cached->setup_fee) !== '0.00') {
                throw new \DomainException('Native list price changed; refresh checkout.');
            }
            $this->requireCheck((string) $item->checkout_config['domains_check'], $line['name'], $this->identity($item), $line['mapping']['action'], true);
            $line['snapshot'] = $snapshot;
            if ($batch !== null) {
                $line['renewal_snapshot'] = $batch[$index]['renewal'];
                $line['contact_tld'] = $offers->contactTld($line['mapping']['tld']);
            }
        } unset($line);
        $this->order = $order; $this->lines = $lines;
    }

    /** Server-only view for prelocking the entire admitted batch before native inserts. */
    public function batch(Order $order): array
    {
        if (!$this->order) { return []; }
        if (DB::transactionLevel() !== 1 || $this->order !== $order) { throw new \DomainException('No current native batch.'); }
        return array_values($this->lines);
    }
    public function recheck(array $line): void
    {
        if (DB::transactionLevel() !== 1 || !$this->order?->exists) { throw new \DomainException('No current native checkout.'); }
        try { $this->requireCheck($line['check'], $line['name'], $line['identity'], $line['mapping']['action'], true); }
        catch (CheckoutCheckExpired $e) { throw new \App\Exceptions\DisplayException($e->getMessage()); }
    }

    private function requireCheck(string $token, string $name, array $identity, string $action, bool $locked = false): SaleSelection
    {
        try { return $this->eligibility->require($token, $name, $identity, $locked); }
        catch (CheckoutCheckExpired $e) {
            if ($action === 'transfer') {
                throw new \App\Exceptions\DisplayException('The transfer check expired. Edit or remove the transfer in your cart, then try checkout again.');
            }
            throw $e;
        }
    }

    /** Binding can consume only this transaction's admitted batch; externally constructed DTOs have no authority. */
    public function take(Service $service): array
    {
        if (DB::transactionLevel() !== 1 || !$this->order?->exists || (int) $service->order_id !== (int) $this->order->id
            || (int) $service->user_id !== (int) $this->order->user_id || $service->currency_code !== 'USD' || (int) $service->quantity !== 1) {
            throw new \DomainException('Domain service has no checkout admission.');
        }
        foreach ($this->lines as $index => $line) {
            if ((int) $line['product_id'] === (int) $service->product_id && (int) $line['plan_id'] === (int) $service->plan_id) {
                unset($this->lines[$index]); return [...$line, 'order_id' => (int) $this->order->id];
            }
        }
        throw new \DomainException('Domain line was not admitted.');
    }

    private function validate(CartItem $item, Product $product): array
    {
        $plan = $item->plan()->firstOrFail(); $mapping = $this->native->mapping($product, $plan);
        if (!$mapping || (string) $item->quantity !== '1' || !empty($item->config_options) || $product->configOptions()->exists()
            || $item->cart?->currency_code !== 'USD' || $product->stock !== null) { throw new \DomainException('Domain line requires one name, no options and USD.'); }
        return $mapping;
    }
    private function name(CartItem $item, array $mapping): string
    {
        $input = $item->checkout_config['domain'] ?? null;
        if (!is_string($input)) { throw new \DomainException('Enter a domain name.'); }
        return CanonicalName::parse($input, [$mapping['tld']->tld])['domain'];
    }
    private function identity(CartItem $item): array
    {
        return [(int) $item->cart_id, (int) $item->product_id, (int) $item->plan_id];
    }
}
