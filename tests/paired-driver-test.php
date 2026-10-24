<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/paired-driver.php';
use App\Models\{Invoice, Order, Service};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainReservations, HolderPricing, RefundProof};

$holdings = [];
foreach ([0, 1] as $i) {
    foreach (['register', 'transfer'] as $action) {
        $op = $scenarioNew($i, $action, placeholderComFixtureName('paired-'.$i.'-'.$action.'-placeholder.com'));
        $scenarioCompose($i, $action); $state->calls = [];
        scenarioRetained($op->domain, static function () use ($sellers, $clock, $tlds, $i): void {
            scenarioSwitch($sellers, $clock, $tlds[1 - $i]->server_id);
        });
        $money = scenarioMoney(); $result = $engine->execute($op->id);
        scenarioExecution($result); same($money, scenarioMoney(), 'Initial dispatch adds no debt, payment or credit');
        same($action === 'register' ? 'pending' : 'uncertain', $result->state, 'Original holder accepts exactly one purchase, awaiting financial proof');
        same([$i === 0 ? ($action === 'register' ? 'Purchase' : 'TP_CreateOrder') : ($action === 'register' ? 'registerDomain' : 'transferDomain')], $scenarioSends(), 'Exactly one write through the frozen registrar');
        same($tlds[$i]->server_id, $result->server_id, 'Operation retains original server');
        same($i === 0 ? 'enom' : 'namesilo', $result->registrar_key, 'Operation retains original provider');
        same(null, $result->cost['actual'] ?? null, 'Purchase acknowledgement is not actual charge proof');
        same(null, $result->domain->provider_expires_at, 'No premature registrar expiry');
        same(null, $result->domain->service->expires_at, 'Native expiry remains null');
        same('paid', Invoice::findOrFail($op->invoice_id)->status, 'Paid native invoice retained');
        $calls = $state->calls; $money = scenarioMoney();
        foreach ([1, 0, 1, 0] as $seller) {
            scenarioRetained($result->domain, static function () use ($sellers, $clock, $tlds, $seller, $engine, $op, $result): void {
                scenarioSwitch($sellers, $clock, $tlds[$seller]->server_id);
                same($result->state, $engine->execute($op->id)->state, 'Duplicate worker retains pending or unknown result');
            });
        }
        same($calls, $state->calls, 'Duplicate workers after repeated switches send nothing');
        same($money, scenarioMoney(), 'No duplicate invoice, charge or credit');
        commerceReject(fn () => RefundProof::requireUnsent($result->fresh(), $result->domain->fresh()));
        commerceReject(fn () => DomainReservations::releaseTerminal($result->domain->domain));
        same($result->domain->domain, $result->domain->fresh()->reservation_name, 'Accepted and unknown names remain globally reserved');
        scenarioSwitch($sellers, $clock, $tlds[1 - $i]->server_id);
        $cart = commerceCart($user);
        commerceAdd($cart, $native->product($tlds[1 - $i], $action === 'register' ? 'transfer' : 'register'), strtoupper($result->domain->domain).'.');
        $money = scenarioMoney(); $orders = Order::count(); $services = Service::count();
        commerceCheckout($user, $cart);
        same($money, scenarioMoney(), 'Competing account/action checkout rolls back all native finance');
        same($orders, Order::count(), 'Losing checkout creates no order'); same($services, Service::count(), 'Losing checkout creates no service');
        same(1, Domain::where('reservation_name', $result->domain->domain)->count(), 'One global canonical-name winner across registrars and actions');
        $holdings[] = $result->domain->fresh();
    }
    same(($i + 1) * 2, Domain::count(), $i === 0 ? 'Enom-only holdings before adding NameSilo' : 'Both holders coexist');
}
same(4, Order::count(), 'One order per acquisition'); same(4, Invoice::count(), 'One invoice per acquisition');
same(4, Operation::count(), 'No fallback operation'); same(4, Service::count(), 'One native service per domain');

// The public cart also refuses duplicate canonical register/transfer lines before checkout.
foreach ([0, 1] as $i) {
    scenarioSwitch($sellers, $clock, $tlds[$i]->server_id);
    $cart = commerceCart($user); $name = placeholderComFixtureName('same-action-'.$i.'-placeholder.com');
    commerceAdd($cart, $native->product($tlds[$i], 'register'), strtoupper($name).'.');
    $money = scenarioMoney();
    commerceReject(fn () => commerceAdd($cart, $native->product($tlds[$i], 'transfer'), $name));
    same(1, $cart->items()->count(), 'Duplicate canonical action never enters cart');
    same($money, scenarioMoney(), 'Cart refusal creates no native finance');
}
// Retained holder retail is per account even when another, cheaper account sells.
$rules = new Paymenter\Extensions\Servers\Domains\Services\PricingRules;
$rules->saveDefaultRule($tlds[0]->server_id, ['mode' => 'fixed', 'markup' => '0', 'round_up' => 'none',
    'fixed' => ['register' => '100.00', 'renew' => '40.00', 'transfer' => '100.00']], $prices->republish(...));
scenarioSwitch($sellers, $clock, $tlds[1]->server_id);
$holder = new HolderPricing($prices);
same('40.00', $holder->renewal($holdings[0], 'USD', $clock->now())->retail, 'Expensive non-selling Enom holder retains its own retail');
same('10.40', $holder->renewal($holdings[2], 'USD', $clock->now())->retail, 'NameSilo retail is independent of expensive Enom');
// Switch to no seller: both accounts remain readable for their existing holders.
scenarioSwitch($sellers, $clock, null);
foreach ($holdings as $domain) {
    same($domain->registrar_key === 'enom' ? '40.00' : '10.40', $holder->renewal($domain, 'USD', $clock->now())->retail, 'No seller retains exact account price for both acquisition holders');
}
echo "Paired native acquisition, one-use dispatch, canonical duplicate and holder-retail scenarios passed\n";
$nativeSuccessful = true;
