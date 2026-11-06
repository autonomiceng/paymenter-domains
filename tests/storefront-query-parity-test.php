<?php
// Native rows, guarded historical corruption and SQL growth need the installed catalogue fixture.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';require __DIR__.'/Support/storefront-reader.php';
require __DIR__.'/Support/storefront-catalogue.php';require __DIR__.'/Support/storefront-queries.php';
use Paymenter\Extensions\Servers\Domains\Services\{Rdap,TransferPrecheck};
// Parity and mutation cases use the base and a separate synthetic Enom account.
extract(commerceFixture(true,registrars:['enom']));
$eligibility=activeFixtureEligibility($bindings,$clock,new TransferPrecheck(new Rdap(static fn()=>['status'=>503,'body'=>''])));
$store=activeFixtureStorefront($sellers,$prices,$native,$bindings,$eligibility,$clock);
storefrontQueryChecks(compact('bindings', 'prices', 'sellers', 'native', 'now', 'reads'), $store);
echo "Native storefront catalogue parity and query checks passed.\n";$nativeSuccessful=true;
