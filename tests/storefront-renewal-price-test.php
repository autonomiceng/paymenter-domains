<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/storefront.php';require __DIR__.'/Support/storefront-catalogue.php';
extract(commerceFixture(true,registrars:['enom']));
extract(storefrontCatalogueFixture(compact('bindings','prices','sellers','native','now','reads'),['net','org'],[1]));
storefrontFixtureRule($native,$catalogueTlds['net'],'17.99','10.99','10.99');
storefrontFixtureRule($native,$catalogueTlds['org'],'20.99','21.99','21.99');
$store=activeFixtureStorefront($sellers,$prices,$native,$bindings,activeFixtureEligibility($bindings,$clock),$clock);
$rows=array_column($store->endings(),null,'tld');
foreach(['net'=>'$10.99','org'=>'$21.99'] as $tld=>$renew){
    $result=$store->check('new-example.'.$tld);
    same($renew,$result['renew'],'Search renewal stays with its own synced TLD: '.$tld);
    same($rows[$tld]['renew'],$result['renew'],'Search and price list use the same renewal record');
}
storefrontViews();require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
$data=storefrontData();$data['endings']=$store->endings();
$html=view('domains::search',$data)->render();
$chips=storefrontDom($html)->query('//p[contains(@class,"domains-endings")]')->item(0)->textContent;
expect(str_contains($chips,'first year')&&!str_contains($chips,'/yr'),'Registration chips visibly label the first year, not the annual renewal');
$nativeSuccessful=true;echo "Per-TLD search renewal prices passed.\n";
