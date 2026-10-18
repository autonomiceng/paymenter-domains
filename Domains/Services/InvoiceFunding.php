<?php
// Adapted from Enom 444f31c funding checks; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Enums\InvoiceTransactionStatus;
use App\Models\{Invoice, InvoiceItem, Product, Service};
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
        private readonly Collection $services, private readonly Collection $products) {}

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
            foreach ([\Illuminate\Database\Events\TransactionCommitted::class, \Illuminate\Database\Events\TransactionRolledBack::class] as $event) {
                Event::listen($event, static function ($e): void {
                    foreach (self::$contexts as $context => $_) {
                        if ($context->connection === $e->connection && $e->connection->transactionLevel() === 0) { $context->active = false; }
                    }
                });
            }
        }
        $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        $items = $invoice->items()->orderBy('id')->lockForUpdate()->get();
        $transactions = $invoice->transactions()->orderBy('id')->lockForUpdate()->get();
        $ids = $items->where('reference_type', (new Service)->getMorphClass())->pluck('reference_id')->unique();
        $services = Service::whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $products = Product::whereKey($services->pluck('product_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $context = new self(DB::connection(), $invoice, $items, $transactions, $services, $products);
        self::$contexts[$context] = true; return $context;
    }

    /** Funding evidence only. Engine19 supplies its current domain then operation under ordered locks. */
    public function requirePaid(Operation $op, Domain $domain): array
    {
        if (!$this->active || !isset(self::$contexts[$this]) || DB::connection() !== $this->connection || DB::transactionLevel() !== 1) { throw new \LogicException('Funding snapshot expired.'); }
        $invoice = $this->invoice; $items = $this->items; $transactions = $this->transactions;
        $service = $this->services->get($op->service_id);
        if (!$service) { throw new \DomainException('Missing native service.'); }
        $item = $items->firstWhere('id', $op->invoice_item_id);
        if (!$item instanceof InvoiceItem || $op->funding_basis !== 'paid_invoice' || $invoice->status !== Invoice::STATUS_PAID
            || $service->status === Service::STATUS_CANCELLED || PricingRule::minor($op->amount) <= 0) {
            throw new \DomainException('Domain invoice binding is not funded.');
        }
        InvoiceBinding::requireIdentity($op, $domain, $service, $invoice, $item, $this->products->get($service->product_id));
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
