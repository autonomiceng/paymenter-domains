<?php
// Adapted from Enom 444f31c funding checks; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Native;

use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryInvoices, HookRegistration, InvoiceBinding, NativeDomainGuard, PricingRule};

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
    private ?Collection $transactions = null;
    private function __construct(private readonly \Illuminate\Database\Connection $connection,
        private readonly ReceiptLocks $receipt) {}

    public function __clone(): void { throw new \LogicException('Funding contexts cannot be cloned.'); }
    public function __serialize(): array { throw new \LogicException('Funding contexts cannot be persisted.'); }
    public function __debugInfo(): array { return []; }

    /** Capture the COMPLETE native prefix before ANY Domains locks; callers cannot omit reversals/lines.
     * The private snapshot expires at outer commit/rollback, even when a new transaction later starts.
     */
    public static function lock(int $invoiceId): self
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Funding joins the outer claim transaction.'); }
        self::$contexts ??= new \WeakMap;
        if (HookRegistration::claim(Event::getFacadeRoot(),self::class)) {
            foreach ([\Illuminate\Database\Events\TransactionBeginning::class, \Illuminate\Database\Events\TransactionCommitted::class, \Illuminate\Database\Events\TransactionRolledBack::class] as $event) {
                Event::listen($event, static function ($e): void {
                    foreach (self::$contexts as $context => $_) {
                        if ($context->connection === $e->connection && ($e->connection->transactionLevel() === 0
                            || ($e instanceof \Illuminate\Database\Events\TransactionBeginning && $e->connection->transactionLevel() === 1))) { $context->active = false; }
                    }
                });
            }
        }
        $context = new self(DB::connection(), HoldingNativeLocks::receipt($invoiceId));
        self::$contexts[$context] = true; return $context;
    }

    /** Read the locked invoice's payment rows (HoldingNativeLocks::transactions). Callers take this last, after their
     * server, account, domain and operation rows. A caller that locks nothing after the prefix gets them on first read. */
    public function lockPayments(): void
    {
        $this->requireLive();
        $this->transactions ??= HoldingNativeLocks::transactions([$this->receipt->invoice->id]);
    }

    /** Acquisition lifecycle under the same native prefix; renewal/complimentary authority is separate. */
    public function requireInitial(Operation $op, Domain $domain): array
    {
        return self::requireInitialFacts($op,$this->facts($domain));
    }

    public static function requireInitialFacts(Operation $op,array $l): array
    {
        $paid=$op->funding_basis==='complimentary'?ComplimentaryInvoices::require($op,$l):self::requirePaidFacts($op,$l);
        if($op->funding_basis==='complimentary'){InvoiceBinding::requireIdentity($op,$l['domain'],$l['service'],$l['invoices']->firstWhere('id',$op->invoice_id),$l['items']->firstWhere('id',$op->invoice_item_id),$l['product']);}$service=$l['service'];$plan=$l['plan'];
        if (!in_array($service->status, [Service::STATUS_PENDING, Service::STATUS_ACTIVE], true)
            || $l['cancel']->contains('service_id', $service->id) || !$plan
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
        $service = $this->receipt->checkout->services->firstWhere('id', $op->service_id);
        if ($service->isDirty()) { throw new \DomainException('Acquisition lifecycle changed.'); }
        return $service;
    }

    /** Funding evidence only. Engine19 supplies its current domain then operation under ordered locks. */
    public function requirePaid(Operation $op, Domain $domain): array
    {
        return self::requirePaidFacts($op,$this->facts($domain));
    }

    /** Detached native snapshots; callers cannot alter the private live funding context through them. */
    public function initialFacts(Domain $domain): array
    {
        $copy=static function($row){
            $snapshot=$row->withoutRelations();$snapshot->setRawAttributes($row->getAttributes(),true);return $snapshot;
        };
        return array_map(static fn($value)=>$value instanceof Collection?$value->map($copy):($value===null?null:$copy($value)),$this->facts($domain));
    }

    private function facts(Domain $domain): array
    {
        $this->requireLive();
        $service=$this->receipt->checkout->services->firstWhere('id', $domain->service_id);
        if(!$service){throw new \DomainException('Missing native service.');}
        return ['domain'=>$domain,'service'=>$service,'product'=>$this->receipt->checkout->products->firstWhere('id', $service->product_id),
            'plan'=>$this->receipt->checkout->plans->firstWhere('id', $service->plan_id),'orders'=>$this->receipt->checkout->orders,'cancel'=>$this->receipt->checkout->cancellations,
            'invoices'=>collect([$this->receipt->invoice]),'items'=>$this->receipt->items,'transactions'=>$this->payments()];
    }

    private function requireLive(): void
    {
        if (!$this->active || !isset(self::$contexts[$this]) || DB::connection() !== $this->connection || DB::transactionLevel() !== 1 || !$this->connection->getPdo()->inTransaction()) { throw new \LogicException('Funding snapshot expired.'); }
    }

    private function payments(): Collection { $this->lockPayments(); return $this->transactions; }

    public static function requirePaidFacts(Operation $op,array $l): array
    {
        $domain=$l['domain'];$invoice=$l['invoices']->firstWhere('id',$op->invoice_id);
        if(!$invoice){throw new \DomainException('Missing native invoice.');}
        $items=$l['items']->where('invoice_id',$invoice->id);$transactions=$l['transactions']->where('invoice_id',$invoice->id);
        $service = $l['service'];
        if (!$service) { throw new \DomainException('Missing native service.'); }
        $order = $l['orders']->firstWhere('id',$service->order_id);
        if (!$order || (int) $order->user_id !== (int) $service->user_id) { throw new \DomainException('Holding native authority changed.'); }
        $item = $items->firstWhere('id', $op->invoice_item_id);
        if (!$item instanceof InvoiceItem || $op->funding_basis !== 'paid_invoice' || $invoice->status !== Invoice::STATUS_PAID
            || $service->status === Service::STATUS_CANCELLED || PricingRule::minor($op->amount) <= 0) {
            throw new \DomainException('Domain invoice binding is not funded.');
        }
        $product = $l['product'];
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
