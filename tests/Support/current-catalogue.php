<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require_once __DIR__.'/commerce.php';
require_once __DIR__.'/native-commerce.php';
require_once __DIR__.'/catalogue-snapshot.php';

use Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting;
use Paymenter\Extensions\Servers\Domains\Services\{NativeCatalogue, PriceCatalogue, PricingRules, Runtime, SellerPolicies};

extract(commerceFixture(true, registrars: ['enom']));
$prices = new PriceCatalogue(new PricingRules);
$native = new NativeCatalogue($prices);
$sellers = new SellerPolicies($prices, $bindings);
app()->instance(NativeCatalogue::class, $native);
app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class);
Runtime::register();
// Fresh installation resolves the checkout adapters before this fixture binds its synthetic account reader.
foreach ([\Paymenter\Extensions\Servers\Domains\Services\CartAdmission::class,
    \Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility::class,
    \Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class] as $type) {
    app()->forgetInstance($type);
}
$clock->instant = $now = $now->modify('+1 second');
CallerRouting::reset();
