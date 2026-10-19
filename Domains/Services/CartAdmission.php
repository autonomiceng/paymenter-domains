<?php
// Adapted from Enom 444f31c Services/CartOrdering.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Classes\Cart as NativeCart;
use App\Models\{CartItem, Order, Product, Service};
use Illuminate\Support\Facades\{Auth, DB, Event};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;

/** One batch in native Order Creating, after ALL native user/coupon/product locks. Dormant until slice 39. */
final class CartAdmission
{
    private bool $booted = false;
    private ?Order $order = null;
    private array $lines = [];
    public function __construct(private readonly NativeCatalogue $native, private readonly SellerPolicies $sellers,
        private readonly CheckoutEligibility $eligibility, private readonly Clock $clock) {}

    public function boot(): void
    {
        if ($this->booted) { return; } $this->booted = true;
        foreach ([\App\Events\CartItem\Creating::class, \App\Events\CartItem\Updating::class] as $event) {
            Event::listen($event, fn ($e) => $this->prepare($e->cartItem));
        }
        Event::listen(\App\Events\Order\Creating::class,function($e):void {
            try{$this->admit($e->order);}catch(\DomainException|\InvalidArgumentException $error){throw new \App\Exceptions\DisplayException('Your domain offer changed or expired. Remove this line from your cart and search again for a current offer.');}
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
            'description' => $mapping['action'] === 'transfer' ? 'Enter the transfer code after checkout.' : 'Enter the full domain name.']];
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
        $selected = $this->sellers->selection($mapping['tld']->tld, $mapping['action'], $mapping['term'], $this->clock->now());
        if ($selected->serverId !== (int) $product->server_id || $selected->fingerprint !== $mapping['tld']->account_fingerprint) {
            throw new \DomainException('Seller changed; search again.');
        }
        $token = $this->eligibility->observe($name, $selected, $this->identity($item));
        // Drop arbitrary fields, including transfer codes and client-supplied authority claims.
        $item->checkout_config = ['domain' => $name, 'domains_check' => $token];
    }

    private function admit(Order $order): void
    {
        $cart = NativeCart::get(); $lines = $selections = $names = [];
        // Use the SAME cached native items which checkout just locked; never reload a different batch here.
        foreach ($cart->items as $item) {
            $product = Product::findOrFail($item->product_id);
            if (!$this->native->mapping($product)) { continue; }
            if (DB::transactionLevel() !== 1 || $this->order || (int) Auth::id() !== (int) $order->user_id
                || ($cart->user_id !== null && (int) $cart->user_id !== (int) $order->user_id) || $order->currency_code !== 'USD') {
                throw new \DomainException('Domain checkout requires its native transaction and owner.');
            }
            $mapping = $this->validate($item, $product);
            $name = $this->name($item, $mapping);
            if (isset($names[$name])) { throw new \DomainException('Duplicate domain cart line.'); } $names[$name] = true;
            $selection = $this->eligibility->require((string) ($item->checkout_config['domains_check'] ?? ''), $name, $this->identity($item));
            if ($selection->serverId !== (int) $product->server_id || $selection->fingerprint !== $mapping['tld']->account_fingerprint
                || $selection->tld !== $mapping['tld']->tld || $selection->action !== $mapping['action'] || $selection->term !== $mapping['term']) {
                throw new \DomainException('Domain check does not match this product.');
            }
            $retail = RetailLine::native($item->price);
            $selections[] = $selection;
            $lines[] = ['item_id' => $item->id, 'product_id' => $product->id, 'plan_id' => $item->plan_id, 'name' => $name,
                'mapping' => $mapping, 'retail' => $retail, 'selection' => $selection,
                'admitted_user_id' => (int) $order->user_id, 'check' => $item->checkout_config['domains_check'], 'identity' => $this->identity($item)];
        }
        if (!$lines) { return; }
        // Exactly one call for the whole checkout. Any exception aborts the native outer transaction.
        $snapshots = $this->sellers->admit($selections, $this->clock->now());
        foreach ($lines as $index => &$line) {
            $snapshot = $snapshots[$index]; $item = $cart->items->firstWhere('id', $line['item_id']);
            $price = $item->plan()->firstOrFail()->prices()->where('currency_code', 'USD')->sole();
            $cached = $item->plan->prices->where('currency_code', 'USD')->sole();
            if (RetailLine::decimal($price->price) !== $snapshot->retail || RetailLine::decimal($price->setup_fee) !== '0.00'
                || RetailLine::decimal($cached->price) !== $snapshot->retail || RetailLine::decimal($cached->setup_fee) !== '0.00') {
                throw new \DomainException('Native list price changed; refresh checkout.');
            }
            $this->eligibility->require((string) $item->checkout_config['domains_check'], $line['name'], $this->identity($item), true);
            if ($snapshot->freshUntil <= $this->clock->now()) { throw new \DomainException('Price evidence expired during admission.'); }
            $line['snapshot'] = $snapshot;
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
        $this->eligibility->require($line['check'], $line['name'], $line['identity'], true);
        if ($line['snapshot']->freshUntil <= $this->clock->now()) { throw new \DomainException('Admitted evidence expired.'); }
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
            || $item->cart?->currency_code !== 'USD' || $product->stock === 0) { throw new \DomainException('Domain line requires one name, no options and USD.'); }
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
