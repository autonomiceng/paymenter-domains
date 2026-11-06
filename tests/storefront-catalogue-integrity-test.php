<?php
// Native rows, guarded historical corruption and SQL growth need the installed catalogue fixture.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';require __DIR__.'/Support/storefront-reader.php';
require __DIR__.'/Support/storefront-catalogue.php';require __DIR__.'/Support/storefront-queries.php';
use Paymenter\Extensions\Servers\Domains\Services\{Rdap,TransferPrecheck};
// The corruption matrix arranges its own second Enom account; no assertion uses NameSilo.
extract(commerceFixture(true,registrars:['enom']));
$eligibility=activeFixtureEligibility($bindings,$clock,new TransferPrecheck(new Rdap(static fn()=>['status'=>503,'body'=>''])));
$store=activeFixtureStorefront($sellers,$prices,$native,$bindings,$eligibility,$clock);
storefrontCorruptionChecks(compact('bindings', 'prices', 'sellers', 'native', 'now', 'reads'), $store);
echo "Native storefront catalogue corruption checks passed.\n";$nativeSuccessful=true;
