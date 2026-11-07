<?php
// Native SQL and the real cache/fence path are required; a stub cannot expose its duplicate reads.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/storefront-reader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{Rdap,TransferPrecheck};
extract(commerceFixture(true));
$eligibility=activeFixtureEligibility($bindings,$clock,new TransferPrecheck(new Rdap(static fn()=>['status'=>503,'body'=>''])));
$store=activeFixtureStorefront($sellers,$prices,$native,$bindings,$eligibility,$clock);$store->endings();
DB::enableQueryLog();DB::flushQueryLog();$store->endings();$queries=DB::getQueryLog();DB::disableQueryLog();
echo 'Warm storefront SQL: '.json_encode(array_column($queries,'query'))."\n";
// Preserve MAIN's original exact SQL and bindings assertion.
same([['query' => 'select * from "settings" where "settingable_type" is null and "settingable_id" is null and "key" = ?',
    'bindings' => ['domains_offer_upgrade']]], array_map(static fn ($q) => array_intersect_key($q, array_flip(['query', 'bindings'])),
    $queries), 'Warm public projection reads only the current catalogue gate');
$nativeSuccessful=true;echo "Warm storefront uses only its current catalogue gate.\n";
