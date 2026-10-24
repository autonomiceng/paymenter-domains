<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Invoice, InvoiceItem, InvoiceTransaction, Order, Plan, Product, Service, ServiceCancellation, TaxRate, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};

/** Shared native prefix; every invoice precedes sorted orders, then sorted services. */
final class HoldingNativeLocks
{
    public static function take(int $ownerId, int $serviceId, ?int $staffId = null, bool $advisory = false,
        ?Domain $seed = null, ?int $expectedOrderId = null, bool $adoption = false, array $additionalServices = [], bool $completeInvoices = false): array
    {
        if (!$advisory && DB::transactionLevel() !== 1) { throw new \LogicException('Holding locks require the outer transaction.'); }
        $users = User::whereKey(array_filter([$ownerId, $staffId]))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        if (!$advisory) { NativeUserDeletion::requireOwner($ownerId); }
        $user = $users->firstWhere('id', $ownerId) ?? throw new \DomainException('Missing owner.');
        $staff = $staffId === null ? null : $users->firstWhere('id', $staffId);
        if ($staff) { $staff->setRelation('role', $staff->role()->when(!$advisory,fn($q)=>$q->lockForUpdate())->first()); }
        $user->setRelation('properties', $user->properties()->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get());
        $credit = $user->credits()->where('currency_code', 'USD')->when(!$advisory,fn($q)=>$q->lockForUpdate())->first();
        // Current locking reads also see invoices committed after an older native cron read view.
        // All owner invoices precede ALL services, matching InvoiceFunding, including mixed invoices.
        $opIds=$advisory?Operation::where('domain_id',$seed?->id)->orderByDesc('id')->limit(101)->pluck('id'):null;
        if($advisory&&$opIds->count()>100){throw new \DomainException('Advisory operation history requires staff review.');}
        $invoiceIds=$advisory?Operation::whereIn('id',$opIds)->whereNotNull('invoice_id')->limit(100)->pluck('invoice_id'):null;
        $itemIds=$advisory?Operation::whereIn('id',$opIds)->limit(100)->pluck('invoice_item_id'):null;
        // Adoption must see even a foreign-owner invoice referencing this service. Discover keys before invoices.
        $related = $adoption ? InvoiceItem::where('reference_type', (new Service)->getMorphClass())->where('reference_id', $serviceId)->pluck('invoice_id') : collect();
        $invoices = Invoice::where(fn ($q) => $q->where('user_id', $user->id)->when($adoption, fn ($q) => $q->orWhereIn('id', $related)))->when($advisory,fn($q)=>$q->whereIn('id',$invoiceIds->merge($related)))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get(); $ids = $invoices->pluck('id');
        $items = InvoiceItem::where(fn ($q) => $q->whereIn('invoice_id', $ids)->when($adoption, fn ($q) => $q->orWhere(fn ($q) => $q->where('reference_type', (new Service)->getMorphClass())->where('reference_id', $serviceId))))->when($advisory&&!$completeInvoices,fn($q)=>$q->where(fn ($q) => $q->whereIn('id', $itemIds)->when($adoption, fn ($q) => $q->orWhere(fn ($q) => $q->where('reference_type', (new Service)->getMorphClass())->where('reference_id', $serviceId)))))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        // A current item read must cover discovery, even if an older read view hid a foreign invoice.
        // Missing invoice locks abort here, before any order/service/ledger locks; never acquire them late.
        if ($adoption && $items->pluck('invoice_id')->diff($ids)->isNotEmpty()) { throw new \DomainException('Adoption invoice coverage changed. Verify again.'); }
        $transactions = InvoiceTransaction::whereIn('invoice_id', $ids)->when($advisory,fn($q)=>$q->limit(101))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        if($advisory&&$transactions->count()>100){throw new \DomainException('Advisory payment history requires staff review.');}
        $serviceIds = $items->where('reference_type', (new Service)->getMorphClass())->pluck('reference_id')->push($serviceId)->merge($additionalServices)->unique();
        [$orders, $services] = self::lockServices($serviceIds->all(), $advisory, $expectedOrderId === null ? [] : [$expectedOrderId]);
        $products = Product::whereKey($services->pluck('product_id'))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $plans = Plan::whereKey($services->pluck('plan_id'))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $service = $services->firstWhere('id', $serviceId) ?? throw new \DomainException('Missing holding service.');
        $product = $products->firstWhere('id', $service->product_id); $plan = $plans->firstWhere('id', $service->plan_id);
        $cancel = ServiceCancellation::whereIn('service_id', $serviceIds)->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $cancel = NativeCancellation::withIntents($cancel, $serviceIds->all(), $advisory);
        $configs = $service->configs()->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $relatedProperties = \App\Models\Property::where('model_type', $service->getMorphClass())->whereIn('model_id', $additionalServices)->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $properties = $service->properties()->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        if (!$product || !$plan) { throw new \DomainException('Missing holding route.'); }
        $markers = $product->settings()->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $upgrades = DB::table('product_upgrades')->where('product_id', $product->id)->orWhere('upgrade_id', $product->id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $links = DB::table('config_option_products')->where('product_id', $product->id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        // Native Settings::tax selection, using current locked rows rather than a cached read view.
        $country = $user->properties->where('key', 'country')->value('value');
        if ($country) { $country = array_search($country, config('app.countries')) ?: $country; }
        $tax = TaxRate::whereIn('country', [$country, 'all'])->orderByRaw('country = ? desc', [$country])->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->first() ?? 0;
        return compact('user', 'staff', 'credit', 'invoices', 'items', 'transactions', 'orders', 'services', 'products', 'plans', 'service', 'product', 'plan', 'cancel', 'configs', 'properties', 'markers', 'upgrades', 'links', 'tax', 'opIds', 'relatedProperties');
    }

    /** Shared current-read prefix for mixed invoices. Never acquire an order after a service. */
    public static function lockServices(array $serviceIds, bool $advisory = false, array $expectedOrderIds = []): array
    {
        if (!$advisory && DB::transactionLevel() !== 1) { throw new \LogicException('Identity locks join the outer transaction.'); }
        $orderIds = Service::whereKey($serviceIds)->pluck('order_id')->merge($expectedOrderIds)->filter()->unique();
        $orders = Order::whereKey($orderIds)->orderBy('id')->when(!$advisory, fn ($q) => $q->lockForUpdate())->get();
        $services = Service::whereKey($serviceIds)->orderBy('id')->when(!$advisory, fn ($q) => $q->lockForUpdate())->get();
        foreach ($services as $service) {
            if ($service->order_id !== null && !$orders->contains('id', $service->order_id)) {
                throw new \DomainException('Holding native order changed.');
            }
        }
        return [$orders, $services];
    }
}
