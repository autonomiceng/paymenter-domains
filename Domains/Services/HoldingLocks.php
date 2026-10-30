<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption, Domain, Operation, Price, Tld};

/** Internal billing/save prefix, joining exactly one caller-owned transaction. Never performs I/O. */
final class HoldingLocks
{
    public static function take(Domain $seed, ?int $staffId = null, bool $advisory = false, array $additionalServices = [], bool $completeInvoices = false, bool $foreignInvoices = false): array
    {
        if (!$advisory && DB::transactionLevel() !== 1) { throw new \LogicException('Holding locks require the outer transaction.'); }
        ['user' => $user, 'staff' => $staff, 'credit' => $credit, 'invoices' => $invoices, 'items' => $items,
            'transactions' => $transactions, 'orders' => $orders, 'services' => $services, 'products' => $products,
            'plans' => $plans, 'service' => $service, 'product' => $product, 'plan' => $plan, 'cancel' => $cancel,
            'configs' => $configs, 'properties' => $properties, 'markers' => $markers, 'tax' => $tax,
            'upgrades' => $upgrades, 'links' => $links, 'opIds' => $opIds]
            = $nativeLocks = HoldingNativeLocks::take((int) $seed->user_id, (int) $seed->service_id, $staffId, $advisory, $seed, adoption: $foreignInvoices, additionalServices: $additionalServices, completeInvoices: $completeInvoices);
        $order = $orders->firstWhere('id', $service->order_id);
        $server = Server::whereKey($seed->server_id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->firstOrFail();
        $account = Account::where('server_id', $seed->server_id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->firstOrFail();
        $settings = $server->settings()->when($advisory,fn($q)=>$q->whereIn('key',['domains_credentials','domains_verified_credentials','domains_dns_tools']))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $tld = Tld::where(['server_id' => $seed->server_id, 'tld' => $seed->tld])->when(!$advisory,fn($q)=>$q->lockForUpdate())->firstOrFail();
        $prices = Price::where(['server_id' => $seed->server_id, 'tld' => $seed->tld, 'action' => 'renew'])->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $domain = Domain::whereKey($seed->id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->firstOrFail();
        $operations = Operation::where('domain_id', $seed->id)->when($advisory,fn($q)=>$q->whereIn('id',$opIds)->limit(100))->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $adoption = $domain->adoption_id === null ? null : Adoption::whereKey($domain->adoption_id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->first();
        if ($domain->service_id !== $seed->service_id || $domain->user_id !== $seed->user_id || $domain->server_id !== $seed->server_id || $domain->tld !== $seed->tld
            || $domain->adoptionPending() || !$domain->billing_managed || $domain->reservation_name !== $domain->domain || $domain->authority_generation < 1
            || $server->extension !== 'Domains' || $account->account_fingerprint !== $domain->account_fingerprint || $account->registrar_key !== $domain->registrar_key
            || $tld->account_fingerprint !== $domain->account_fingerprint || $tld->registrar_key !== $domain->registrar_key) {
            throw new \DomainException('Holding native authority changed.');
        }
        $nativeIdentity = HoldingNativeIdentity::capture($domain, $nativeLocks, $tld);
        $identity_source = HoldingIdentitySource::resolve($domain, $nativeIdentity, $adoption, $account, $cancel);
        $service->setRelation('user', $user);
        $rows = [$user, ...$user->properties, ...$invoices, ...$items, ...$transactions, ...$orders, ...$services, ...$products, ...$plans, ...$cancel, ...$configs, ...$properties, ...$markers, $server, $account, ...$settings, $tld, ...$prices, $domain, ...($adoption ? [$adoption] : []), ...$operations, ...$upgrades, ...$links, ...($tax === 0 ? [] : [$tax])];
        $stamp = hash('sha256', json_encode([array_map(static fn ($row) => method_exists($row, 'getRawOriginal') ? $row->getRawOriginal() : (array) $row, $rows), $identity_source], JSON_THROW_ON_ERROR));
        return compact('identity_source', 'adoption', 'advisory', 'credit', 'staff', 'stamp', 'prices', 'transactions', 'domain', 'service', 'product', 'plan', 'user', 'tax', 'server', 'account', 'settings', 'tld', 'operations', 'invoices', 'items', 'cancel', 'services', 'orders', 'products') + ['relatedProperties' => $nativeLocks['relatedProperties']];
    }
}
