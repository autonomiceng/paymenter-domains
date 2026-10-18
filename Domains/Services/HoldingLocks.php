<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Invoice, InvoiceItem, InvoiceTransaction, Plan, Product, Server, Service, ServiceCancellation, TaxRate, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Price, Tld};

/** Internal billing/save prefix, joining exactly one caller-owned transaction. Never performs I/O. */
final class HoldingLocks
{
    public static function take(Domain $seed, ?int $staffId = null): array
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Holding locks require the outer transaction.'); }
        $users = User::whereKey(array_filter([$seed->user_id, $staffId]))->orderBy('id')->lockForUpdate()->get();
        $user = $users->firstWhere('id', $seed->user_id) ?? throw new \DomainException('Missing owner.');
        $staff = $staffId === null ? null : $users->firstWhere('id', $staffId);
        if ($staff) { $staff->setRelation('role', $staff->role()->lockForUpdate()->first()); }
        $user->setRelation('properties', $user->properties()->orderBy('id')->lockForUpdate()->get());
        $credit = $user->credits()->where('currency_code', 'USD')->lockForUpdate()->first();
        // Current locking reads also see invoices committed after an older native cron read view.
        // All owner invoices precede ALL services, matching InvoiceFunding, including mixed invoices.
        $invoices = Invoice::where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get(); $ids = $invoices->pluck('id');
        $items = InvoiceItem::whereIn('invoice_id', $ids)->orderBy('id')->lockForUpdate()->get();
        $transactions = InvoiceTransaction::whereIn('invoice_id', $ids)->orderBy('id')->lockForUpdate()->get();
        $serviceIds = $items->where('reference_type', (new Service)->getMorphClass())->pluck('reference_id')->push($seed->service_id)->unique();
        $services = Service::whereKey($serviceIds)->orderBy('id')->lockForUpdate()->get();
        $products = Product::whereKey($services->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
        $plans = Plan::whereKey($services->pluck('plan_id'))->orderBy('id')->lockForUpdate()->get();
        $service = $services->firstWhere('id', $seed->service_id) ?? throw new \DomainException('Missing holding service.');
        $product = $products->firstWhere('id', $service->product_id); $plan = $plans->firstWhere('id', $service->plan_id);
        $cancel = ServiceCancellation::whereIn('service_id', $serviceIds)->orderBy('id')->lockForUpdate()->get();
        $configs = $service->configs()->orderBy('id')->lockForUpdate()->get();
        $properties = $service->properties()->orderBy('id')->lockForUpdate()->get();
        if (!$product || !$plan) { throw new \DomainException('Missing holding route.'); }
        $markers = $product->settings()->orderBy('id')->lockForUpdate()->get();
        $upgrades = DB::table('product_upgrades')->where('product_id', $product->id)->orWhere('upgrade_id', $product->id)->lockForUpdate()->get();
        $links = DB::table('config_option_products')->where('product_id', $product->id)->lockForUpdate()->get();
        // Native Settings::tax selection, using current locked rows rather than a cached read view.
        $country = $user->properties->where('key', 'country')->value('value');
        if ($country) { $country = array_search($country, config('app.countries')) ?: $country; }
        $tax = TaxRate::whereIn('country', [$country, 'all'])->orderByRaw('country = ? desc', [$country])->orderBy('id')->lockForUpdate()->first() ?? 0;
        $server = Server::whereKey($seed->server_id)->lockForUpdate()->firstOrFail();
        $account = Account::where('server_id', $seed->server_id)->lockForUpdate()->firstOrFail();
        $settings = $server->settings()->orderBy('id')->lockForUpdate()->get();
        $tld = Tld::where(['server_id' => $seed->server_id, 'tld' => $seed->tld])->lockForUpdate()->firstOrFail();
        $prices = Price::where(['server_id' => $seed->server_id, 'tld' => $seed->tld, 'action' => 'renew'])->orderBy('id')->lockForUpdate()->get();
        $domain = Domain::whereKey($seed->id)->lockForUpdate()->firstOrFail();
        $operations = Operation::where('domain_id', $seed->id)->orderBy('id')->lockForUpdate()->get();
        $c = $domain->snapshot['checkout'] ?? [];
        if ($domain->service_id !== $seed->service_id || $domain->user_id !== $seed->user_id || $domain->server_id !== $seed->server_id || $domain->tld !== $seed->tld
            || !$domain->billing_managed || $domain->reservation_name !== $domain->domain || $domain->authority_generation < 1
            || (int) $service->user_id !== (int) $user->id || $service->currency_code !== 'USD' || (string) $service->quantity !== '1'
            || $service->expires_at !== null || $server->extension !== 'Domains' || (int) $product->server_id !== (int) $server->id
            || $account->account_fingerprint !== $domain->account_fingerprint || $account->registrar_key !== $domain->registrar_key
            || $tld->account_fingerprint !== $domain->account_fingerprint || $tld->registrar_key !== $domain->registrar_key
            || ($c['user_id'] ?? null) !== (int) $user->id || ($c['service_id'] ?? null) !== (int) $service->id
            || ($c['order_id'] ?? null) !== (int) $service->order_id || ($c['product_id'] ?? null) !== (int) $product->id
            || ($c['annual_plan_id'] ?? null) !== (int) $plan->id || ($c['name'] ?? null) !== $domain->domain
            || $plan->priceable_type !== $product->getMorphClass() || (int) $plan->priceable_id !== (int) $product->id
            || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || (int) $plan->billing_period !== 1
            || !$product->hidden || $product->allow_quantity !== 'separated' || $product->slug !== NativeCatalogue::slug($tld, $c['action'] ?? '')
            || $configs->isNotEmpty() || $upgrades->isNotEmpty() || $links->isNotEmpty()
            || $properties->where('key', 'domain')->count() !== 1 || $properties->firstWhere('key', 'domain')->value !== $domain->domain) {
            throw new \DomainException('Holding native authority changed.');
        }
        foreach (['domains_catalogue' => '1', 'domains_tld_id' => (string) $tld->id, 'domains_action' => $c['action'], 'domains_registrar' => $domain->registrar_key] as $key => $value) {
            $rows = $markers->where('key', $key);
            if ($rows->count() !== 1 || $rows->first()->value !== $value || $rows->first()->encrypted || $rows->first()->type !== 'string') {
                throw new \DomainException('Holding product markers changed.');
            }
        }
        $service->setRelation('user', $user);
        $rows = [$user, ...$user->properties, ...$invoices, ...$items, ...$transactions, ...$services, ...$products, ...$plans, ...$cancel, ...$configs, ...$properties, ...$markers, $server, $account, ...$settings, $tld, ...$prices, $domain, ...$operations];
        $stamp = hash('sha256', json_encode(array_map(static fn ($row) => $row->getRawOriginal(), $rows), JSON_THROW_ON_ERROR));
        return compact('credit', 'staff', 'stamp', 'prices', 'transactions', 'domain', 'service', 'product', 'plan', 'user', 'tax', 'server', 'account', 'settings', 'tld', 'operations', 'invoices', 'items', 'cancel');
    }
}
