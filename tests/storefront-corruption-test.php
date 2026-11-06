<?php
// Enom 444f31c search/pricing scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/Support/storefront-reader.php';
require __DIR__.'/Support/storefront-catalogue.php';
require __DIR__.'/Support/storefront-queries.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, RateLimiter, Route};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\StorefrontController;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Price};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, Rdap, Storefront, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

// Native catalogue projection and current provider-cost contracts need the real SQLite fixture.
extract(commerceFixture(true));$clock??=new FakeClock($now);
$eligibility=activeFixtureEligibility($bindings,$clock,new TransferPrecheck(new Rdap(static fn()=>['status'=>503,'body'=>''])));
$store=activeFixtureStorefront($sellers,$prices,$native,$bindings,$eligibility,$clock);
storefrontCorruptionChecks(compact('bindings','prices','sellers','native','now','reads'),$store);
$nativeSuccessful=true;echo "Active storefront group passed\n";
