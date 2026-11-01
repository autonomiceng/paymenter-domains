<?php
// Adapted from Enom 444f31c Services/CartOrdering.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Invoice, InvoiceItem, Order, Product, Service};
use Illuminate\Support\Facades\{DB, Event};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\PriceSnapshot;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, Tld};

/** Synchronous native hooks, dormant until 39. No jobs, provider calls or spending grants. */
final class InvoiceBinding
{
    private \WeakMap $creating;
    private array $prepared = [];
    private array $services = [];
    public function __construct(private readonly CartAdmission $admission, private readonly NativeCatalogue $native,
        private readonly PriceCatalogue $prices, private readonly Clock $clock) { $this->creating = new \WeakMap; }

    public function boot(): void
    {
        if(!HookRegistration::claim(Event::getFacadeRoot(),self::class)){return;}
        Event::listen(\App\Events\Order\Creating::class, fn ($e) => $this->prepare($e->order));
        Service::creating(function (Service $service): void {
            if (!$this->native->mapping($service->product, $service->plan)) { return; }
            $this->admitIdentity($service);
        });
        Event::listen(\App\Events\Service\Created::class, function ($e): void {
            if (isset($this->creating[$e->service])) {
                $this->services[$e->service->id] = $this->creating[$e->service]; unset($this->creating[$e->service]);
            }
        });
        Event::listen(\App\Events\InvoiceItem\Creating::class, function ($e): void {
            $line = $e->invoiceItem->reference_type === (new Service)->getMorphClass() ? ($this->services[$e->invoiceItem->reference_id] ?? null) : null;
            if ($line) {
                $e->invoiceItem->description = InvoiceDescription::acquisition($line['name'],
                    $line['selection']->action, $line['selection']->term, $this->clock->now());
            }
        });
        Event::listen(\App\Events\InvoiceItem\Created::class, fn ($e) => $this->bind($e->invoiceItem));
        foreach ([\Illuminate\Database\Events\TransactionCommitted::class, \Illuminate\Database\Events\TransactionRolledBack::class] as $event) {
            Event::listen($event, function ($e): void {
                if ($e->connection === DB::connection() && $e->connection->transactionLevel() === 0) {
                    $this->prepared = $this->services = []; $this->creating = new \WeakMap;
                }
            });
        }
    }

    /** The route guard and checkout hook share one exact-object admission, never a caller flag. */
    public function admitIdentity(Service $service): void
    {
        if (isset($this->creating[$service])) { return; }
        if (!$service->product || !$service->plan || !$this->native->mapping($service->product, $service->plan)) { NativeIdentity::refuse(); }
        $line = $this->admission->take($service);
        $annual = $this->prepared[$line['item_id']] ?? throw new \DomainException('Domain renewal projection was not admitted.');
        $this->creating[$service] = [...$line, ...$annual];
    }

    /** Only the admitted checkout line may initially persist its canonical service property. */
    public function identityProperty(\App\Models\Property $property): bool
    {
        $line=$this->services[$property->model_id]??null;
        return DB::transactionLevel()>=1&&$line!==null&&$property->model_type===(new Service)->getMorphClass()
            &&$property->key==='domain'&&$property->value===$line['name']&&empty($property->custom_property_id)
            &&!\App\Models\Property::where('model_type',$property->model_type)->where('model_id',$property->model_id)->where('key','domain')->exists();
    }

    private function prepare(Order $order): void
    {
        $lines = $this->admission->batch($order); if (!$lines) { return; }
        // All price/annual projections precede every domain lock, for the ENTIRE batch.
        foreach ($lines as $line) {
            $s = $line['snapshot'];
            $tld = Tld::whereKey($line['mapping']['tld']->id)->lockForUpdate()->firstOrFail();
            $renew = $this->prices->current($s->serverId, $s->fingerprint, $s->tld, 'renew', 1, 'USD', $this->clock->now(), true, $tld);
            $product = Product::findOrFail($line['product_id']); $annual = $this->native->annualPlan($product);
            $price = new \App\Classes\Price(['price' => $renew->retail, 'currency' => $order->currency],
                apply_exclusive_tax: true, tax: \App\Classes\Settings::tax($order->user));
            $this->prepared[$line['item_id']] = ['annual_plan_id' => $annual->id, 'annual_price' => RetailLine::decimal($price->price), 'renewal' => self::price($renew), 'setup_tld' => $tld];
        }
        // Reentrant reservation checks may lock these rows later; never first acquire another
        // domain after operations for an earlier cart line. Global unique name arbitrates absence.
        $domains = Domain::whereIn('reservation_name', array_column($lines, 'name'))->orderBy('id')->lockForUpdate()->get();
        Operation::whereIn('domain_id', $domains->pluck('id'))->orderBy('id')->lockForUpdate()->get();
    }

    public function bind(InvoiceItem $item): void
    {
        $item = $item->fresh() ?? throw new \DomainException('Invoice item must be persisted.');
        if ($item->reference_type !== (new Service)->getMorphClass()) { return; }
        $service = Service::findOrFail($item->reference_id);
        $existing = Operation::where('invoice_item_id', $item->id)->first();
        if ($existing) {
            // Repeated delivery is read-only. It never refreshes snapshots or reprojects a service.
            self::requireIdentity($existing, $existing->domain()->firstOrFail(), $service, $item->invoice()->firstOrFail(), $item->fresh()); return;
        }
        if (!$this->native->mapping($service->product, $service->plan)) { return; }
        $line = $this->services[$service->id] ?? throw new \DomainException('Domain invoice has no native admission.');
        $this->admission->recheck($line);
        $s = $line['snapshot']; $invoice = $item->invoice()->firstOrFail();
        if (DB::transactionLevel() !== 1 || $invoice->status !== Invoice::STATUS_PENDING || $invoice->currency_code !== 'USD'
            || $service->currency_code !== 'USD' || (int) $service->order_id !== $line['order_id']
            || (int) $service->user_id !== $line['admitted_user_id'] || (int) $invoice->user_id !== (int) $service->user_id || (int) $service->product_id !== (int) $line['product_id']
            || (int) $service->plan_id !== (int) $line['plan_id'] || (string) $service->quantity !== '1'
            || (string) $item->quantity !== '1' || RetailLine::decimal($item->price) !== $line['retail']['total']
            || $service->properties()->where('key', 'domain')->sole()->value !== $line['name']) {
            throw new \DomainException('Native domain invoice changed after admission.');
        }
        $checkout = [...$line['retail'], 'name' => $line['name'], 'user_id' => (int) $service->user_id, 'order_id' => (int) $service->order_id,
            'service_id' => (int) $service->id, 'product_id' => (int) $line['product_id'], 'plan_id' => (int) $line['plan_id'],
            'annual_plan_id' => (int) $line['annual_plan_id'], 'annual_price' => $line['annual_price'],
            'invoice_id' => (int) $invoice->id, 'invoice_item_id' => (int) $item->id, 'term' => $s->term, 'action' => $s->action, 'currency' => 'USD'];
        $domain = DomainReservations::reserve(['service_id' => $service->id, 'user_id' => $service->user_id,
            'domain' => $line['name'], 'server_id' => $s->serverId, 'registrar_key' => $s->registrar,
            'account_fingerprint' => $s->fingerprint, 'authority_generation' => 1, 'status' => $s->action === 'register' ? 'registration_pending' : 'transfer_pending',
            'snapshot' => ['checkout' => $checkout], 'renewal_policy' => $line['renewal']], [$s->tld]);
        $operation = Operation::create(['domain_id' => $domain->id, 'service_id' => $service->id, 'server_id' => $s->serverId,
            'registrar_key' => $s->registrar, 'account_fingerprint' => $s->fingerprint, 'authority_generation' => 1,
            'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'operation_key' => 'invoice-item:'.$item->id,
            'action' => $s->action, 'term' => $s->term, 'funding_basis' => 'paid_invoice', 'state' => 'awaiting_payment',
            'amount' => $line['retail']['total'], 'currency' => 'USD', 'policy' => ['checkout' => $checkout, 'price' => self::price($s), 'seller_revision' => $line['selection']->policyRevision],
            'cost' => ['estimated' => $s->wholesale, 'currency' => 'USD', 'unit' => 'term_total'], 'payload' => ContactSetup::capture($service->user()->firstOrFail(), $line['setup_tld'])]);
        $service->forceFill(['plan_id' => $line['annual_plan_id'], 'price' => $line['annual_price'], 'label' => $line['name'], 'expires_at' => null]);
        NativeDomainGuard::initial($service, $domain);
        self::requireIdentity($operation, $domain, $service->fresh(), $invoice, $item);
        // Opaque cart evidence is temporary; never persist it as native customer properties.
        $service->properties()->where('key', 'domains_check')->delete();
        unset($this->services[$service->id]);
    }

    /** Current native rows must match the frozen operation; no DTO alone grants authority. */
    public static function requireIdentity(Operation $op, Domain $domain, Service $service, Invoice $invoice, InvoiceItem $item, ?Product $product = null): void
    {
        if ($domain->adoption_id !== null || isset($domain->snapshot['holding']) || $domain->provenance === 'adopted') {
            throw new \DomainException('Adoption does not authorize acquisition.');
        }
        $c = $op->policy['checkout'] ?? [];
        $product ??= $service->product()->firstOrFail();
        foreach (['user_id' => $service->user_id, 'order_id' => $service->order_id, 'service_id' => $service->id,
            'product_id' => $service->product_id, 'annual_plan_id' => $service->plan_id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id] as $key => $id) {
            if (!isset($c[$key]) || $c[$key] !== (int) $id) { throw new \DomainException('Frozen checkout identity changed.'); }
        }
        if ((int) $op->domain_id !== (int) $domain->id || (int) $op->service_id !== (int) $service->id || (int) $domain->service_id !== (int) $service->id
            || (int) $domain->user_id !== (int) $service->user_id || (int) $invoice->user_id !== (int) $service->user_id
            || (int) $op->invoice_id !== (int) $invoice->id || (int) $op->invoice_item_id !== (int) $item->id || (int) $item->invoice_id !== (int) $invoice->id
            || $item->reference_type !== $service->getMorphClass() || (int) $item->reference_id !== (int) $service->id
            || (string) $item->quantity !== '1' || (string) $service->quantity !== '1' || $c['name'] !== $domain->domain || ($domain->reservation_name !== $domain->domain && ($domain->reservation_name !== null || $op->safe_code !== UnpaidAcquisitionProof::CODE))
            || $c['term'] !== (int) $op->term || $c['action'] !== $op->action || $c['currency'] !== $op->currency
            || $service->currency_code !== 'USD' || $invoice->currency_code !== 'USD' || $op->currency !== 'USD'
            || $op->authority_generation !== $domain->authority_generation || $domain->authority_generation < 1
            || $op->server_id !== $domain->server_id || $op->registrar_key !== $domain->registrar_key || $op->account_fingerprint !== $domain->account_fingerprint
            || (int) $product->id !== (int) $service->product_id || (int) $product->server_id !== (int) $domain->server_id
            || ($domain->snapshot['checkout'] ?? null) !== $c
            || PricingRule::minor($item->price) !== PricingRule::minor($op->amount) || PricingRule::minor($c['total']) !== PricingRule::minor($op->amount)) {
            throw new \DomainException('Frozen domain invoice binding changed.');
        }
    }
    private static function price(PriceSnapshot $s): array
    {
        return [...get_object_vars($s), 'observedAt' => $s->observedAt->format(DATE_ATOM), 'freshUntil' => $s->freshUntil->format(DATE_ATOM)];
    }
}
