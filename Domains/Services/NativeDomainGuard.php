<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Invoice, InvoiceItem, InvoiceTransaction, Order, Plan, Product, Service, ServiceConfig, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};

/** Dormant native adapters; boot once in 39, before checkout/binding. No financial/provider execution. */
final class NativeDomainGuard
{
    private bool $booted = false;
    private static ?Service $projecting = null;
    private static ?Service $initialIdentity = null;
    public function __construct(private readonly HolderPricing $pricing, private readonly Clock $clock) {}

    public function boot(): void
    {
        if ($this->booted) { return; } $this->booted = true;
        app()->bind(\App\Services\Invoice\ProcessPaidInvoiceService::class, ManagedPaidInvoice::class);
        Domain::updating(static function (Domain $d): void {
            if ($d->isDirty(['price_mode', 'billing_managed'])) { throw new \DomainException('Managed pricing authority requires a dedicated audited transition.'); }
        });
        foreach (['updating', 'deleting'] as $event) {
            InvoiceTransaction::$event(static function (InvoiceTransaction $payment) use ($event): void {
                if (Operation::whereIn('invoice_id', [$payment->invoice_id, $payment->getRawOriginal('invoice_id')])->exists()) {
                    // Native gateways settle an existing processing row; signed financial fields stay fixed.
                    $dirty = array_diff(array_keys($payment->getDirty()), ['status', 'fee', 'updated_at', 'is_credit_transaction']);
                    if ($event === 'updating' && $dirty === [] && (int) $payment->is_credit_transaction === (int) $payment->getRawOriginal('is_credit_transaction')
                        && ($payment->status->value === $payment->getRawOriginal('status') || ($payment->getRawOriginal('status') !== 'succeeded'
                            && in_array($payment->status->value, ['processing', 'succeeded', 'failed'], true)))) { return; }
                    throw new \DomainException('Domain financial evidence is retained; append an audited adjustment.');
                }
            });
        }
        Service::updating(fn (Service $s) => $this->saving($s));
        Service::deleting(static function (Service $s): void {
            if (Domain::whereIn('service_id', self::ids($s))->exists()) { throw new \DomainException('Managed holding services are retained.'); }
        });
        User::deleting(static function (User $user): void {
            if (Domain::whereIn('user_id', self::ids($user))->exists()
                || Domain::whereIn('service_id', Service::whereIn('order_id', Order::whereIn('user_id', self::ids($user))->select('id'))->select('id'))->exists()) {
                throw new \DomainException('Managed domain owners are retained.');
            }
        });
        Order::updating(static function (Order $order): void {
            if ($order->isDirty(['id', 'user_id']) && Domain::whereIn('service_id', Service::whereIn('order_id', self::ids($order))->select('id'))->exists()) {
                throw new \DomainException('Holding order ownership is permanent.');
            }
        });
        Order::deleting(static function (Order $order): void {
            if (Domain::whereIn('service_id', Service::whereIn('order_id', self::ids($order))->select('id'))->exists()) {
                throw new \DomainException('Managed holding orders are retained.');
            }
        });
        foreach (['saving', 'deleting'] as $event) {
            ServiceConfig::$event(static function (ServiceConfig $config): void {
                // Respect morph types so unrelated hosting upgrades with colliding IDs still work.
                $ids = [];
                foreach ([$config->getAttributes(), $config->getRawOriginal()] as $row) {
                    if (($row['configurable_type'] ?? null) === (new Service)->getMorphClass()) { $ids[] = $row['configurable_id'] ?? null; }
                    if (!isset($row['configurable_type'])) { $ids[] = $row['service_id'] ?? null; }
                }
                if (Domain::whereIn('service_id', array_filter($ids))->exists()) { throw new \DomainException('Managed domains cannot use native configs.'); }
            });
        }
        Product::updating(static function (Product $p): void {
            if ($p->isDirty(['id', 'server_id', 'slug', 'hidden', 'allow_quantity']) && Domain::whereIn('service_id', Service::whereIn('product_id', self::ids($p))->select('id'))->exists()) {
                throw new \DomainException('Holding product mapping is permanent.');
            }
        });
        Plan::updating(static function (Plan $p): void {
            if ($p->isDirty(['id', 'priceable_id', 'priceable_type', 'type', 'billing_period', 'billing_unit'])
                && Domain::whereIn('service_id', Service::whereIn('plan_id', self::ids($p))->select('id'))->exists()) { throw new \DomainException('Holding plan must remain annual.'); }
        });
        Product::deleting(static function (Product $p): void {
            if (Domain::whereIn('service_id', Service::whereIn('product_id', self::ids($p))->select('id'))->exists()) { throw new \DomainException('Holding products are retained.'); }
        });
        Plan::deleting(static function (Plan $p): void {
            if (Domain::whereIn('service_id', Service::whereIn('plan_id', self::ids($p))->select('id'))->exists()) { throw new \DomainException('Holding annual plans are retained.'); }
        });
        InvoiceItem::creating(static function (InvoiceItem $item): void {
            if (Operation::where('invoice_id', $item->invoice_id)->whereIn('action', ['renew','privacy_purchase','privacy_renew'])->exists()
                || ($item->reference_type === (new Service)->getMorphClass() && Domain::where('service_id', $item->reference_id)->exists())) {
                throw new \DomainException('Managed renewal items require the billing authority.');
            }
        });
        foreach (['updating', 'deleting'] as $event) {
            InvoiceItem::$event(static function (InvoiceItem $item): void {
                if (Operation::whereIn('invoice_item_id', self::ids($item))->exists()
                    || Operation::whereIn('invoice_id', [$item->invoice_id, $item->getRawOriginal('invoice_id')])->whereIn('action', ['renew','privacy_purchase','privacy_renew'])->exists()) {
                    throw new \DomainException('Issued domain invoice items and renewal membership are immutable.');
                }
            });
        }
        Invoice::updating(static function (Invoice $invoice): void {
            if ($invoice->isDirty(['id', 'user_id', 'currency_code', 'due_at']) && Operation::whereIn('invoice_id', self::ids($invoice))->exists()) {
                throw new \DomainException('Issued domain invoice routing and calendar are immutable.');
            }
        });
        Invoice::deleting(static function (Invoice $invoice): void {
            if (Operation::whereIn('invoice_id', self::ids($invoice))->exists()) { throw new \DomainException('Issued domain invoices are retained.'); }
        });
    }

    /** Eloquent deletion targets the original primary key even if the in-memory key was changed. */
    private static function ids(\Illuminate\Database\Eloquent\Model $model): array
    {
        return array_values(array_unique(array_filter([$model->getKey(), $model->getRawOriginal($model->getKeyName())])));
    }

    private function saving(Service $service): void
    {
        if (self::$projecting === $service) { return; }
        $domain = Domain::whereIn('service_id', self::ids($service))->first(); if (!$domain) { return; }
        self::assertSave($service, $domain, $this->clock->now(), $this->pricing);
    }

    /** Single native save contract; callers own the ordered outer transaction for dirty prices. */
    public static function assertSave(Service $service, Domain $domain, \DateTimeImmutable $now, HolderPricing $pricing): void
    {
        if ((int) $domain->service_id !== (int) $service->id) { throw new \DomainException('Foreign holding service.'); }
        // Native paid/cron code must never fabricate a registrar expiry.
        $service->expires_at = null;
        if ($service->isDirty(['product_id', 'plan_id', 'user_id', 'order_id', 'quantity', 'currency_code', 'coupon_id'])) {
            throw new \DomainException('Managed service binding is permanent.');
        }
        if (!$service->isDirty('price')) { return; } // Lifecycle saves do not depend on stale retail/mode.
        if (DB::transactionLevel() !== 1) { throw new \DomainException('Native price saves require an outer transaction.'); }
        $locked = HoldingLocks::take($domain); $current = $locked['service'];
        foreach (['product_id', 'plan_id', 'user_id', 'order_id', 'quantity', 'currency_code', 'coupon_id', 'price', 'status'] as $key) {
            if ((string) $service->getRawOriginal($key) !== (string) $current->getRawOriginal($key)) { throw new \DomainException('Stale native service save.'); }
        }
        $retail = $pricing->renewal($locked['domain'], 'USD', $now, true, $locked['tld']);
        if ($locked['domain']->price_mode !== 'standard' || $retail->retained
            || PricingRule::minor($service->price) !== PricingRule::minor(self::gross($retail->retail, $locked['tax']))) {
            throw new \DomainException('Native reprice requires fresh holding-account annual retail.');
        }
    }

    /** Internal initial checkout transition, validated against the just-frozen admitted checkout. */
    public static function initial(Service $service, Domain $domain): void
    {
        $c = $domain->snapshot['checkout'] ?? [];
        if (DB::transactionLevel() !== 1 || $domain->provider_expires_at !== null
            || (int) $service->id !== (int) $domain->service_id || (int) $service->plan_id !== ($c['annual_plan_id'] ?? null)
            || (int) $service->product_id !== ($c['product_id'] ?? null) || (int) $service->user_id !== ($c['user_id'] ?? null)
            || $service->expires_at !== null || RetailLine::decimal($service->price) !== ($c['annual_price'] ?? null)
            || array_diff(array_keys($service->getDirty()), ['plan_id', 'price', 'label', 'expires_at'])) { throw new \DomainException('Unadmitted initial projection.'); }
        if (self::$initialIdentity !== null) { throw new \LogicException('Nested initial identity projection refused.'); }
        self::$initialIdentity = $service;
        try { self::persist($service); } finally { self::$initialIdentity = null; }
    }

    /** Caller holds HoldingLocks; retained retail is allowed only through this billing projection. */
    public function project(array $locked): array
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Projection requires holding locks.'); }
        $domain = $locked['domain']; $service = $locked['service'];
        if ($domain->price_mode !== 'standard') { throw new \DomainException('Complimentary authority belongs to renewal execution.'); }
        $retail = $this->pricing->renewal($domain, 'USD', $this->clock->now(), true, $locked['tld']);
        $gross = self::gross($retail->retail, $locked['tax']);
        $service->forceFill(['price' => $gross, 'expires_at' => null]); self::persist($service);
        return ['id' => $retail->id, 'revision' => $retail->revision, 'annual_net' => $retail->retail, 'annual_gross' => $gross,
            'currency' => 'USD', 'unit' => 'term_total', 'retained' => $retail->retained, 'staff_alert' => $retail->staffAlert];
    }

    public static function identityProjection(Service $service): bool
    {
        return self::$initialIdentity === $service
            && array_diff(array_keys($service->getDirty()), ['plan_id', 'price', 'label', 'expires_at', 'updated_at']) === [];
    }

    private static function persist(Service $service): void
    {
        if (self::$projecting !== null) { throw new \LogicException('Nested projection refused.'); }
        self::$projecting = $service;
        try { $service->save(); } finally { self::$projecting = null; }
    }

    private static function gross(string $retail, \App\Models\TaxRate|int $tax): string
    {
        $price = new \App\Classes\Price(['price' => $retail, 'currency' => \App\Models\Currency::where('code', 'USD')->sole()],
            apply_exclusive_tax: true, tax: $tax);
        return RetailLine::decimal($price->price);
    }
}
