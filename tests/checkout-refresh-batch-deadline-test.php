<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Full native checkout refresh contracts.
declare(strict_types=1);
require __DIR__.'/Support/checkout-refresh-time.php';
require __DIR__.'/Support/checkout-refresh.php';
use Illuminate\Support\Facades\{Cache, DB, Event};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, RegistrarRequest, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CartAdmission, CheckoutEligibility, ObservationSchema, ReadDeferred, RegistrarRegistry, Runtime, SellerPolicies};
$initialOrders=App\Models\Order::count();$initialInvoices=App\Models\Invoice::count();same([0,0],[$initialOrders,$initialInvoices],'Batch fixture starts without native obligations');
// Advance the budget and registrar clocks together; no host scheduling window determines success.
$clock->advance(61); $cart = commerceCart($user);
for ($i = 0; $i < 3; $i++) { commerceAdd($cart, $product, placeholderComFixtureName('slow-batch-'.$i.'-placeholder.com')); $clock->advance(1); }
$lines = $cart->items()->orderBy('id')->get(); $page = $mount($cart); $clock->advance(301);
$wire->delayMs = 6000; $started = $GLOBALS['checkout_fake_tick']; $click($page); $elapsed = ($GLOBALS['checkout_fake_tick'] - $started) / 1000000000;
expect($elapsed <= 15, 'Successful reads consume at most the shared 15-second simulated budget');
same($initialOrders, App\Models\Order::count(), 'Batch deadline leaves payment and orders untouched');
same(3, $cart->items()->count(), 'Batch deadline keeps all cart lines');
foreach ($lines->take(2) as $line) { expect($line->fresh()->checkout_config['domains_check'] !== $line->checkout_config['domains_check'], 'Completed refresh persists before the batch stops'); }
same($lines->last()->checkout_config, $lines->last()->fresh()->checkout_config, 'Timed-out line keeps its previous receipt for retry');
expect(end($wire->timeouts) < 4000, 'Last physical read receives only the remaining batch timeout');
$page->assertDispatched('notify', static fn($event, $params) => str_contains($params[0]['message'], 'We could not get your price') && str_contains($params[0]['message'], 'Try again shortly.'));
$wire->delayMs = 0; $clock->advance(1); $before = count($wire->calls); $click($page);
same($initialOrders+1, App\Models\Order::count(), 'Retry completes the remaining line without starting over');
same($before + 1, count($wire->calls), 'Retry reuses both completed receipts');
same($initialInvoices+1, App\Models\Invoice::count(), 'Only completed checkouts create invoices');
$nativeSuccessful = true; echo "Native checkout simulated batch deadline, retained receipts and exact retry passed.\n";
