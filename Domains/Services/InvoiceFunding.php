<?php
// Adapted from Enom 444f31c funding checks; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Enums\InvoiceTransactionStatus;
use App\Models\{Invoice, InvoiceItem, Plan, Product, Service, ServiceCancellation};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Event};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};

/** Funding evidence only, never a spending grant. Engine19 must hold native prefix BEFORE domain/operation locks. */
final class InvoiceFunding
{
    private static ?\WeakMap $contexts = null;
    private bool $active = true;
    private function __construct(private readonly \Illuminate\Database\Connection $connection,
        private readonly Invoice $invoice, private readonly Collection $items, private readonly Collection $transactions,
        private readonly Collection $orders, private readonly Collection $services, private readonly Collection $products,
        private readonly Collection $plans, private readonly Collection $cancellations) {}

    public function __clone(): void { throw new \LogicException('Funding contexts cannot be cloned.'); }
    public function __serialize(): array { throw new \LogicException('Funding contexts cannot be persisted.'); }
    public function __debugInfo(): array { return []; }

    /** Capture the COMPLETE native prefix before ANY Domains locks; callers cannot omit reversals/lines.
     * The private snapshot expires at outer commit/rollback, even when a new transaction later starts.
     */
    public static function lock(int $invoiceId): self
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Funding joins the outer claim transaction.'); }
        if (self::$contexts === null) {
            self::$contexts = new \WeakMap;
            foreach ([\Illuminate\Database\Events\TransactionBeginning::class, \Illuminate\Database\Events\TransactionCommitted::class, \Illuminate\Database\Events\TransactionRolledBack::class] as $event) {
                Event::listen($event, static function ($e): void {
                    foreach (self::$contexts as $context => $_) {
                        if ($context->connection === $e->connection && ($e->connection->transactionLevel() === 0
                            || ($e instanceof \Illuminate\Database\Events\TransactionBeginning && $e->connection->transactionLevel() === 1))) { $context->active = false; }
                    }
                });
            }
        }
        $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        $items = $invoice->items()->orderBy('id')->lockForUpdate()->get();
        $transactions = $invoice->transactions()->orderBy('id')->lockForUpdate()->get();
        $ids = $items->where('reference_type', (new Service)->getMorphClass())->pluck('reference_id')->unique();
        [$orders, $services] = HoldingNativeLocks::lockServices($ids->all());
        $orders = $orders->keyBy('id'); $services = $services->keyBy('id');
        $products = Product::whereKey($services->pluck('product_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $plans = Plan::whereKey($services->pluck('plan_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $cancellations = ServiceCancellation::whereIn('service_id', $services->keys())->orderBy('id')->lockForUpdate()->get();
        $cancellations = NativeCancellation::withIntents($cancellations, $services->keys()->all());
        $context = new self(DB::connection(), $invoice, $items, $transactions, $orders, $services, $products, $plans, $cancellations);
        self::$contexts[$context] = true; return $context;
    }

    /** Acquisition lifecycle under the same native prefix; renewal/complimentary authority is separate. */
    public function requireInitial(Operation $op, Domain $domain): array
    {
        $paid = $this->requirePaid($op, $domain);
        $service = $this->services->get($op->service_id); $plan = $this->plans->get($service->plan_id);
        if (!in_array($service->status, [Service::STATUS_PENDING, Service::STATUS_ACTIVE], true)
            || $this->cancellations->contains('service_id', $service->id) || !$plan
            || $plan->priceable_type !== (new Product)->getMorphClass() || (int) $plan->priceable_id !== (int) $service->product_id
            || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || (int) $plan->billing_period !== 1) {
            throw new \DomainException('Acquisition lifecycle changed.');
        }
        return $paid;
    }

    public function completeInitial(Operation $op, Domain $domain): void
    {
        NativeDomainGuard::completeInitial($this, $op, $domain);
    }

    /** The live context owns this exact locked object, including its expected prior state. */
    public function initialService(Operation $op, Domain $domain): Service
    {
        $this->requireInitial($op, $domain);
        $service = $this->services->get($op->service_id);
        if ($service->isDirty()) { throw new \DomainException('Acquisition lifecycle changed.'); }
        return $service;
    }

    /** Funding evidence only. Engine19 supplies its current domain then operation under ordered locks. */
    public function requirePaid(Operation $op, Domain $domain): array
    {
        if (!$this->active || !isset(self::$contexts[$this]) || DB::connection() !== $this->connection || DB::transactionLevel() !== 1 || !$this->connection->getPdo()->inTransaction()) { throw new \LogicException('Funding snapshot expired.'); }
        $invoice = $this->invoice; $items = $this->items; $transactions = $this->transactions;
        $service = $this->services->get($op->service_id);
        if (!$service) { throw new \DomainException('Missing native service.'); }
        $order = $this->orders->get($service->order_id);
        if (!$order || (int) $order->user_id !== (int) $service->user_id) { throw new \DomainException('Holding native authority changed.'); }
        $item = $items->firstWhere('id', $op->invoice_item_id);
        if (!$item instanceof InvoiceItem || $op->funding_basis !== 'paid_invoice' || $invoice->status !== Invoice::STATUS_PAID
            || $service->status === Service::STATUS_CANCELLED || PricingRule::minor($op->amount) <= 0) {
            throw new \DomainException('Domain invoice binding is not funded.');
        }
        $product = $this->products->get($service->product_id);
        if (!$product) { throw new \DomainException('Missing locked native product.'); }
        InvoiceBinding::requireIdentity($op, $domain, $service, $invoice, $item, $product);
        $total = $paid = 0; $transactionIds = [];
        foreach ($items as $row) {
            if ((int) $row->invoice_id !== (int) $invoice->id || !ctype_digit((string) $row->quantity) || (int) $row->quantity < 1) { throw new \DomainException('Invalid native invoice line.'); }
            $total += PricingRule::minor($row->price) * (int) $row->quantity;
        }
        foreach ($transactions as $row) {
            if ((int) $row->invoice_id !== (int) $invoice->id) { throw new \DomainException('Foreign native payment.'); }
            if ($row->status === InvoiceTransactionStatus::Succeeded) { $paid += PricingRule::minor($row->amount); $transactionIds[] = $row->id; }
        }
        if ($total <= 0 || $paid < $total) { throw new \DomainException('The full invoice must remain paid.'); }
        return ['invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'invoice_total_minor' => $total,
            'succeeded_net_minor' => $paid, 'transaction_ids' => $transactionIds, 'currency' => 'USD'];
    }
}
