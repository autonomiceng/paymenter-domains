<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Price, Tld};

/** Internal billing/save prefix, joining exactly one caller-owned transaction. Never performs I/O. */
final class HoldingLocks
{
    public static function take(Domain $seed, ?int $staffId = null, bool $advisory = false): array
    {
        if (!$advisory && DB::transactionLevel() !== 1) { throw new \LogicException('Holding locks require the outer transaction.'); }
        ['user' => $user, 'staff' => $staff, 'credit' => $credit, 'invoices' => $invoices, 'items' => $items,
            'transactions' => $transactions, 'orders' => $orders, 'services' => $services, 'products' => $products,
            'plans' => $plans, 'service' => $service, 'product' => $product, 'plan' => $plan, 'cancel' => $cancel,
            'configs' => $configs, 'properties' => $properties, 'markers' => $markers, 'tax' => $tax,
            'upgrades' => $upgrades, 'links' => $links, 'opIds' => $opIds]
            = HoldingNativeLocks::take((int) $seed->user_id, (int) $seed->service_id, $staffId, $advisory, $seed);
        $order = $orders->firstWhere('id', $service->order_id);
        $server = Server::whereKey($seed->server_id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->firstOrFail();
        $account = Account::where('server_id', $seed->server_id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->firstOrFail();
        $settings = $server->settings()->when($advisory,fn($q)=>$q->whereIn('key',['domains_credentials','domains_verified_credentials','domains_dns_tools']))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $tld = Tld::where(['server_id' => $seed->server_id, 'tld' => $seed->tld])->when(!$advisory,fn($q)=>$q->lockForUpdate())->firstOrFail();
        $prices = Price::where(['server_id' => $seed->server_id, 'tld' => $seed->tld, 'action' => 'renew'])->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $domain = Domain::whereKey($seed->id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->firstOrFail();
        $operations = Operation::where('domain_id', $seed->id)->when($advisory,fn($q)=>$q->whereIn('id',$opIds)->limit(100))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $c = $domain->snapshot['checkout'] ?? [];
        if ($domain->service_id !== $seed->service_id || $domain->user_id !== $seed->user_id || $domain->server_id !== $seed->server_id || $domain->tld !== $seed->tld
            || $domain->adoptionPending() || !$domain->billing_managed || $domain->reservation_name !== $domain->domain || $domain->authority_generation < 1
            || !$order || (int) $order->user_id !== (int) $user->id || (int) $service->user_id !== (int) $user->id || $service->currency_code !== 'USD' || (string) $service->quantity !== '1'
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
        $rows = [$user, ...$user->properties, ...$invoices, ...$items, ...$transactions, ...$orders, ...$services, ...$products, ...$plans, ...$cancel, ...$configs, ...$properties, ...$markers, $server, $account, ...$settings, $tld, ...$prices, $domain, ...$operations];
        $stamp = hash('sha256', json_encode(array_map(static fn ($row) => $row->getRawOriginal(), $rows), JSON_THROW_ON_ERROR));
        return compact('advisory', 'credit', 'staff', 'stamp', 'prices', 'transactions', 'domain', 'service', 'product', 'plan', 'user', 'tax', 'server', 'account', 'settings', 'tld', 'operations', 'invoices', 'items', 'cancel');
    }
}
