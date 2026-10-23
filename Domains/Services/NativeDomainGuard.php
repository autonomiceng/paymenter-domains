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
    /** Exact-object, synchronous grants; never exposed to native forms or persisted. */
    private static ?\WeakMap $projections = null;
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
        if (self::identityProjection($service)) { return; }
        $domain = Domain::whereIn('service_id', self::ids($service))->first(); if (!$domain) { return; }
        self::assertSave($service, $domain, $this->clock->now(), $this->pricing);
    }

    /** Native lifecycle saves continue; financial and binding edits require owned authority. */
    public static function assertSave(Service $service, Domain $domain, \DateTimeImmutable $now, HolderPricing $pricing): void
    {
        if ((int) $domain->service_id !== (int) $service->id) { throw new \DomainException('Foreign holding service.'); }
        // Native paid/cron code must never fabricate a registrar expiry.
        $service->expires_at = null;
        if ($service->isDirty(['product_id', 'plan_id', 'user_id', 'order_id', 'quantity', 'currency_code', 'coupon_id'])) {
            throw new \DomainException('Managed service binding is permanent.');
        }
        if (!$service->isDirty('price')) { return; } // Lifecycle saves do not depend on stale retail/mode.
        throw new \DomainException('Managed pricing authority requires a dedicated audited transition.');
    }

    /** Internal initial checkout transition, validated against the just-frozen admitted checkout. */
    public static function initial(Service $service, Domain $domain): void
    {
        $c = $domain->snapshot['checkout'] ?? [];
        if (DB::transactionLevel() !== 1 || $domain->isDirty() || $domain->provider_expires_at !== null
            || (int) $service->id !== (int) $domain->service_id || (int) $service->plan_id !== ($c['annual_plan_id'] ?? null)
            || (int) $service->product_id !== ($c['product_id'] ?? null) || (int) $service->user_id !== ($c['user_id'] ?? null)
            || $service->expires_at !== null || RetailLine::decimal($service->price) !== ($c['annual_price'] ?? null)
            || array_diff(array_keys($service->getDirty()), ['plan_id', 'price', 'label', 'expires_at'])) { throw new \DomainException('Unadmitted initial projection.'); }
        foreach (['id' => 'service_id', 'product_id' => 'product_id', 'plan_id' => 'plan_id', 'user_id' => 'user_id', 'order_id' => 'order_id'] as $key => $frozen) {
            if ((int) $service->getRawOriginal($key) !== ($c[$frozen] ?? null)) { throw new \DomainException('Unadmitted initial projection.'); }
        }
        self::persist($service, $service->getRawOriginal(), $service->getDirty());
    }

    /** Caller holds HoldingLocks; retained retail is allowed only through this billing projection. */
    public function project(array $locked): array
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Projection requires holding locks.'); }
        $domain = $locked['domain']; $service = $locked['service'];
        if (($locked['advisory'] ?? true) || $service->isDirty()) { throw new \DomainException('Holding native authority changed.'); }
        if ($domain->price_mode !== 'standard') { throw new \DomainException('Complimentary authority belongs to renewal execution.'); }
        $retail = $this->pricing->renewal($domain, 'USD', $this->clock->now(), true, $locked['tld']);
        $gross = self::gross($retail->retail, $locked['tax']);
        self::persist($service, $service->getRawOriginal(), ['price' => $gross, 'expires_at' => null]);
        return ['id' => $retail->id, 'revision' => $retail->revision, 'annual_net' => $retail->retail, 'annual_gross' => $gross,
            'currency' => 'USD', 'unit' => 'term_total', 'retained' => $retail->retained, 'staff_alert' => $retail->staffAlert];
    }

    /** The funding context selects the already locked object; callers cannot substitute a Service. */
    public static function completeInitial(InvoiceFunding $funding, Operation $op, Domain $domain): void
    {
        $service = $funding->initialService($op, $domain);
        self::persist($service, $service->getRawOriginal(), ['status' => Service::STATUS_ACTIVE, 'expires_at' => null]);
    }

    /** Existing unsent and full-funding checks remain mandatory for owned terminal projections. */
    public static function terminal(array $locked, Operation $op): void
    {
        if (($locked['advisory'] ?? true) || $op->state !== 'rejected' || !in_array($op->action, ['register', 'transfer'], true)
            || !$locked['operations']->contains(static fn ($row) => $row === $op)) { throw new \DomainException('No retained refund disposition.'); }
        RefundProof::requireUnsent($op, $locked['domain']); RefundPayment::requireCurrent($op, $locked);
        $service = $locked['service'];
        self::persist($service, $service->getRawOriginal(), ['status' => Service::STATUS_CANCELLED, 'expires_at' => null]);
    }

    /** Read-only guard check: a different instance, row, field, value or preimage grants nothing. */
    public static function identityProjection(Service $service): bool
    {
        if (self::$projections === null || !isset(self::$projections[$service])) { return false; }
        self::matchProjection($service); return true;
    }

    private static function matchProjection(Service $service): void
    {
        $grant = self::$projections[$service];
        if (!$grant['active'] || DB::transactionLevel() !== $grant['level'] || DB::connection() !== $grant['connection']
            || !$grant['connection']->getPdo()->inTransaction() || !$service->exists
            || $service->getConnection() !== $grant['connection'] || $service->getKey() !== $grant['id']
            || $service->getKeyName() !== 'id' || $service->getTable() !== (new Service)->getTable()
            || $service->getRawOriginal() !== $grant['before'] || $service->getAttributes() !== $grant['after']
            || $service->getDirty() !== $grant['dirty']) { throw new \DomainException('Unadmitted initial projection.'); }
    }

    /** Private issuance only after an owned writer's checks. One saving event consumes the grant. */
    private static function persist(Service $service, array $before, array $values): void
    {
        if (DB::transactionLevel() !== 1 || !$service->exists || $service->getRawOriginal() !== $before) {
            throw new \LogicException('Projection requires holding locks.');
        }
        if (self::$projections === null) {
            self::$projections = new \WeakMap;
            Service::saving(static function (Service $row): void {
                if (!isset(self::$projections[$row])) { return; }
                self::matchProjection($row); $grant = self::$projections[$row];
                if ($grant['consumed']) { throw new \LogicException('Nested projection refused.'); }
                $grant['consumed'] = true; self::$projections[$row] = $grant;
            });
            Service::updating(static function (Service $row): void {
                if (!isset(self::$projections[$row])) { return; }
                self::matchProjection($row); $grant = self::$projections[$row];
                if (!$grant['consumed'] || $grant['updating']) { throw new \LogicException('Nested projection refused.'); }
                $grant['updating'] = true; self::$projections[$row] = $grant;
            });
            foreach ([\Illuminate\Database\Events\TransactionBeginning::class, \Illuminate\Database\Events\TransactionCommitted::class, \Illuminate\Database\Events\TransactionRolledBack::class] as $event) {
                \Illuminate\Support\Facades\Event::listen($event, static function ($e): void {
                    foreach (self::$projections as $row => $grant) {
                        if ($grant['connection'] === $e->connection && ($e instanceof \Illuminate\Database\Events\TransactionRolledBack
                            || $e->connection->transactionLevel() === 0 || ($e instanceof \Illuminate\Database\Events\TransactionBeginning && $e->connection->transactionLevel() === 1))) {
                            $grant['active'] = false; self::$projections[$row] = $grant;
                        }
                    }
                });
            }
        }
        if (count(self::$projections) !== 0 || array_diff(array_keys($service->getDirty()), array_keys($values))) {
            throw new \LogicException('Nested projection refused.');
        }
        // Savepoint contains late public callbacks too; a caught refusal cannot commit their SQL.
        DB::transaction(static function () use ($service, $before, $values): void {
            $timestamps = $service->timestamps;
            $service->forceFill($values);
            if ($service->isDirty() && $service->usesTimestamps()) { $service->updateTimestamps(); }
            $service->timestamps = false; // Do not let Eloquent restamp a clean same-second timestamp.
            // Copy scalars by value; no mutable caller references or reusable token escapes.
            self::$projections[$service] = ['connection' => DB::connection(), 'level' => DB::transactionLevel(), 'id' => $service->getKey(),
                'before' => $before, 'after' => $service->getAttributes(), 'dirty' => $service->getDirty(), 'active' => true, 'consumed' => false, 'updating' => false];
            try {
                if (!$service->save() || !self::$projections[$service]['consumed']) { throw new \LogicException('Projection requires holding locks.'); }
                $grant = self::$projections[$service];
                if (!$grant['active'] || !$service->exists || DB::transactionLevel() !== $grant['level'] || $service->getAttributes() !== $grant['after']
                    || $service->getConnection() !== $grant['connection'] || $service->getKeyName() !== 'id'
                    || $service->getTable() !== (new Service)->getTable()) { throw new \DomainException('Unadmitted initial projection.'); }
            } catch (\Throwable $e) {
                $service->setRawAttributes($before, true); $service->exists = true;
                $service->setTable((new Service)->getTable())->setKeyName('id')->setConnection(DB::connection()->getName());
                throw $e;
            } finally { $service->timestamps = $timestamps; unset(self::$projections[$service]); }
        });
    }

    public static function gross(string $retail, \App\Models\TaxRate|int $tax): string
    {
        $price = new \App\Classes\Price(['price' => $retail, 'currency' => \App\Models\Currency::where('code', 'USD')->sole()],
            apply_exclusive_tax: true, tax: $tax);
        return RetailLine::decimal($price->price);
    }
}
