<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/paired-driver.php';
use App\Models\{Invoice, Order, Service};
use Illuminate\Support\Facades\{DB, Event};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, SalesPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{DomainReservations, RefundProof};

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
echo "Seller switch inside native checkout rolls back for both registrars\n";
$nativeSuccessful = true;
