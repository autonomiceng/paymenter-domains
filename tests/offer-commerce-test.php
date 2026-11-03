<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native checkout/contact/tax events and persisted invoice snapshots cannot be proved by a unit fixture.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Invoice, Order};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Query};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers;
expect(is_file(dirname(__DIR__).'/Domains/Services/AcquisitionOffers.php'), 'Public and native checkout need a shared offer batch adapter');
require __DIR__.'/Support/offer-commerce.php';
extract(offerCommerceFixture());
$public = $storefront->offer('com', 'register', 3);
$staff = $ports->readOffers(new Query($binding, ['com'], 'register', 3), Context::Staff)[0];
same(['$23.40', '$10.40', $binding->serverId, 3, '23.40'], [$public['price'], $public['renew'], $public['selection']->serverId,
    $public['selection']->term, $staff->retail], 'Public offer and Staff reader use the selected account and exact term total');
$user = commerceUser(); $cart = commerceCart($user);
$line = commerceAdd($cart, $public['product'], 'catalogue-placeholder.com', 3);
$token = $line->checkout_config['domains_check']; $before = $reads->calls;
$admission->refresh(); same($before, $reads->calls, 'Current add receipt survives refresh without another provider read');
commerceCheckout($user, $cart);
same([1, 1, 1], [Order::count(), Invoice::count(), Operation::count()], 'Real native checkout creates one atomic obligation');
$op = Operation::firstOrFail();
same([$binding->serverId, 3, '23.40', '10.40'], [(int)$op->server_id, (int)$op->term, $op->policy['price']['retail'],
    $op->domain->renewal_policy['retail']], 'Acquisition and annual renewal freeze the same admitted batch');
same($before, $reads->calls, 'Locked checkout and invoice binding never resolve a provider');
$nativeSuccessful = true;
echo "Offer commerce: ok\n";
