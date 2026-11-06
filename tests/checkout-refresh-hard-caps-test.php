<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Full native checkout refresh contracts.
declare(strict_types=1);
require __DIR__.'/Support/checkout-refresh.php';
use Illuminate\Support\Facades\{Cache, DB, Event};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, RegistrarRequest, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CartAdmission, CheckoutEligibility, ObservationSchema, ReadDeferred, RegistrarRegistry, Runtime, SellerPolicies};
$initialOrders=App\Models\Order::count();$initialInvoices=App\Models\Invoice::count();same([0,0],[$initialOrders,$initialInvoices],'Batch fixture starts without native obligations');
// A deferred interval exceeding the request wait budget must stop without dispatch or cart loss.
$clock->advance(1); $cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('wait-budget-placeholder.com')); $page = $mount($cart); $clock->advance(301);
$micros = (int)$clock->now()->format('U') * 1000000 + (int)$clock->now()->format('u');
DB::table('domains_read_budgets')->update(['next_api_at' => $micros + 20000000]);
$before = count($wire->calls); $click($page);
same($before, count($wire->calls), 'Wait budget exhaustion does not bypass the pacing guard');
same($initialOrders, App\Models\Order::count(), 'Wait budget exhaustion cannot create an order');
same($line->id, $cart->items()->sole()->id, 'Wait budget exhaustion keeps the cart');
$page->assertDispatched('notify', static fn($event, $params) => str_contains(strtolower($params[0]['message']), 'taking too long'));
// Pacing must never make a minute-cap refusal retryable or charge a refused wire.
DB::table('domains_read_budgets')->update(['window_at' => $micros, 'used' => 60]);
$binding = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tlds[0]->server_id)->sole());
try { $limiter->acquire($binding, RequestOrigin::Interactive); expect(false, 'Minute cap must refuse'); }
catch (ReadDeferred $e) { same(null, $e->retryAfterMicros, 'Minute cap retains a hard refusal even while pacing is active'); }
same(60, (int)DB::table('domains_read_budgets')->value('used'), 'Refused wire never changes the minute reservation');
$nativeSuccessful = true; echo "Native checkout interval and minute-cap refusals preserve cart, money and limiter reservations.\n";
