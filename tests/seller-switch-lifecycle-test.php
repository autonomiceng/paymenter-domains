<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/paired-driver.php';
use App\Models\{Invoice, Order, Service};
use Illuminate\Support\Facades\{DB, Event};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, SalesPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{DomainReservations, RefundProof};

// Deterministic schedules; concurrent database locking needs separate InnoDB acceptance.
foreach ([0, 1] as $i) {
    foreach (['register', 'transfer'] as $action) {
        scenarioSwitch($sellers, $clock, $tlds[$i]->server_id);
        $cart = commerceCart($user); commerceAdd($cart, $native->product($tlds[$i], $action), 'stale-'.$i.'-'.$action.'.com');
        $money = scenarioMoney(); $orders = Order::count(); $services = Service::count();
        foreach ([1 - $i, $i] as $seller) {
            scenarioSwitch($sellers, $clock, $tlds[$seller]->server_id);
            commerceCheckout($user, $cart);
            same($money, scenarioMoney(), 'Old receipt stays stale even after switching back');
            same($orders, Order::count(), 'Stale checkout creates no order'); same($services, Service::count(), 'Stale checkout creates no service');
        }
        // Two valid carts exist together before either action reserves the canonical name.
        $name = 'contended-'.$i.'-'.$action.'.com';
        $winner = commerceCart($user); commerceAdd($winner, $native->product($tlds[$i], $action), strtoupper($name).'.');
        $loser = commerceCart($user); commerceAdd($loser, $native->product($tlds[$i], $action === 'register' ? 'transfer' : 'register'), $name);
        commerceCheckout($user, $winner); $money = scenarioMoney(); $orders = Order::count(); $services = Service::count();
        commerceCheckout($user, $loser);
        same(1, Domain::where('reservation_name', $name)->count(), 'Register/transfer carts have one canonical winner');
        same($money, scenarioMoney(), 'Losing cart creates no debt, credit or payment'); same($orders, Order::count(), 'No losing order'); same($services, Service::count(), 'No losing service');

        $op = $scenarioNew($i, $action, 'unpaid-switch-'.$i.'-'.$action.'.com', false);
        $scenarioCompose($i, $action); $state->calls = [];
        $debt = Invoice::findOrFail($op->invoice_id)->getRawOriginal();
        scenarioRetained($op->domain, static fn () => scenarioSwitch($sellers, $clock, $tlds[1 - $i]->server_id));
        (new Paymenter\Extensions\Servers\Domains\Services\RuntimePorts)->operation($op->fresh());
        same('awaiting_payment', $op->fresh()->state, 'Native runtime leaves unpaid obligation dispatchable after future payment');
        same([], $scenarioSends(), 'Unpaid original obligation never dispatches');
        same($debt, Invoice::findOrFail($op->invoice_id)->getRawOriginal(), 'Seller change leaves unpaid debt frozen');
        scenarioFund($op);
        if ($action === 'transfer') { $inputs->paymentAccepted($op->id); }
        $money = scenarioMoney(); $result = $engine->execute($op->id);
        scenarioExecution($result); same($money, scenarioMoney(), 'Funded dispatch retains all native finance');
        same($action === 'register' ? 'pending' : 'uncertain', $result->state, 'Original unpaid order funds and dispatches with another seller');
        same($tlds[$i]->server_id, $result->server_id, 'Payment does not rebind old seller'); same(1, count($scenarioSends()), 'One monetary send after funding');

        foreach (['first-read', $action === 'register' ? 'before-send' : 'second-read', 'sealed-unknown'] as $phase) {
            $op = $scenarioNew($i, $action, $phase.'-'.$i.'-'.$action.'.com');
            $scenarioCompose($i, $action); $state->calls = []; $state->reads = 0; $switched = 0;
            $switch = static function () use ($sellers, $clock, $tlds, $i, &$switched): void {
                same(0, DB::transactionLevel(), 'Competing seller schedule runs outside financial locks');
                scenarioSwitch($sellers, $clock, $tlds[1 - $i]->server_id); $switched++;
            };
            $state->during = in_array($phase, ['sealed-unknown', 'before-send'], true) ? null : static function ($read) use ($phase, $switch): void {
                if ($read === ($phase === 'first-read' ? 1 : 2)) { $switch(); }
            };
            $state->duringWrite = $phase !== 'before-send' ? null : static function ($command) use ($switch, $op): void {
                if ($command !== 'Purchase' && $command !== 'registerDomain') { return; }
                same('sealed', $op->fresh()->attempt['seal'], 'Send boundary retains durable seal'); $switch();
            };
            $armed = $phase === 'sealed-unknown';
            Operation::updated(static function ($saved) use (&$armed, $op, $switch): void {
                if (!$armed || $saved->id !== $op->id || ($saved->attempt['seal'] ?? null) !== 'sealed') { return; }
                $armed = false;
                DB::afterCommit(static function () use ($saved, $switch): void {
                    same('sealed', $saved->fresh()->attempt['seal'], 'Seal is durable before seller switch'); $switch();
                });
            });
            $state->mode = $phase === 'sealed-unknown' ? 'lost' : '';
            $money = scenarioMoney(); $result = $engine->execute($op->id);
            scenarioExecution($result, $phase === 'sealed-unknown');
            same($money, scenarioMoney(), 'Read/seal switch and dispatch add no financial rows');
            $state->during = null; $state->duringWrite = null; $state->mode = '';
            same(1, $switched, 'Requested lifecycle switch actually executed');
            same($tlds[$i]->server_id, $result->server_id, 'Dispatch uses original holder');
            same(1, count($scenarioSends()), 'Exactly one write across '.$phase);
            same($phase === 'sealed-unknown' || $action === 'transfer' ? 'uncertain' : 'pending', $result->state, 'Conservative lifecycle result');
            $calls = $state->calls;
            foreach ([null, $i, 1 - $i, $i] as $seller) {
                scenarioRetained($result->domain, static function () use ($sellers, $clock, $tlds, $seller, $engine, $op): void {
                    scenarioSwitch($sellers, $clock, $seller === null ? null : $tlds[$seller]->server_id); $engine->execute($op->id);
                });
            }
            same($calls, $state->calls, 'Repeated workers cannot replay after seller changes');
            commerceReject(fn () => RefundProof::requireUnsent($result->fresh(), $result->domain->fresh()));
            commerceReject(fn () => DomainReservations::releaseTerminal($result->domain->domain));
        }
    }
}
// A post-admission switch attempt is refused by the policy lock-order guard; checkout rolls back.
$checkoutSwitch = null;
Event::listen(App\Events\Order\Created::class, static function () use (&$checkoutSwitch): void {
    if ($checkoutSwitch) { $hook = $checkoutSwitch; $checkoutSwitch = null; $hook(); }
});
foreach ([0, 1] as $i) {
    scenarioSwitch($sellers, $clock, $tlds[$i]->server_id);
    $guarded = false;
    $checkoutSwitch = static function () use ($sellers, $clock, $tlds, $i, &$guarded): void {
        expect(DB::transactionLevel() > 0, 'Native checkout holds its transaction after admission');
        try { scenarioSwitch($sellers, $clock, $tlds[1 - $i]->server_id); }
        catch (LogicException $error) { same('Seller change owns its lock order.', $error->getMessage(), 'Exact policy guard refuses nested switch'); $guarded = true; throw $error; }
    };
    $cart = commerceCart($user); $name = 'inside-checkout-'.$i.'.com'; commerceAdd($cart, $native->product($tlds[$i], 'register'), $name);
    $money = scenarioMoney(); $orders = Order::count(); $services = Service::count();
    commerceCheckout($user, $cart); same(null, $checkoutSwitch, 'Checkout pause actually reached'); expect($guarded, 'Policy guard actually refused the switch');
    same(0, Domain::where('reservation_name', $name)->count(), 'Refused switch before binding leaves no reservation');
    same($tlds[$i]->server_id, SalesPolicy::where('tld', 'com')->sole()->server_id, 'Refused switch leaves original policy intact');
    same($money, scenarioMoney(), 'Post-admission refusal rolls back all finance');
    same($orders, Order::count(), 'No partial order'); same($services, Service::count(), 'No partial service');
}
echo "Repeated seller switches across checkout, unpaid funding, reads, durable seals and unknown workers passed\n";
$nativeSuccessful = true;
