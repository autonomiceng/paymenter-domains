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
// The catalogue cache fence (77e182036) reads four tables on every warm request, then the gate is read once.
same(['select distinct "server_id" from "domains_sales_policies"','select * from "domains_accounts" where "server_id" in (?) order by "id" asc',
    'select * from "extensions" where "id" in (?) order by "id" asc','select * from "settings" where "settingable_type" = ? and "settingable_id" in (?) order by "id" asc',
    'select * from "settings" where "settingable_type" is null and "settingable_id" is null and "key" = ?'],array_column($queries,'query'),
    'Warm public projection reads the cache fence, then the current catalogue gate');
same(['domains_offer_upgrade'],end($queries)['bindings'],'Warm public projection reads the current catalogue gate last');
$nativeSuccessful=true;echo "Warm storefront reads the cache fence and its current catalogue gate once.\n";
