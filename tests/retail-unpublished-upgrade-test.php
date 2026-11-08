<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Unpublished own rules stay closed during install.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferState;
use Paymenter\Extensions\Servers\Domains\Services\{Installation,StorefrontCatalogueCache};
extract(commerceFixture(true,registrars:['enom']));$t=$tlds[0]->fresh();$intent=OfferState::decode($t->catalogue_intent);$intent['pricing_rule']=['mode'=>'unreadable'];
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_published_revision'=>null,'catalogue_intent'=>OfferState::json($intent)]);
$before=$t->fresh()->getRawOriginal();$cache=new StorefrontCatalogueCache($clock);$fills=0;$render=static function()use(&$fills):array{$fills++;return [['tld'=>'com']];};$cache->remember($render);
(new Installation)->install();(new Installation)->install();$cache->remember($render);
same($before,$t->fresh()->getRawOriginal(),'Repeated install never republishes an unreadable unpublished own rule');same(1,$fills,'Skipped own rule never churns the warm display cache');
echo "Native unpublished retail upgrade remains a no-op\n";$nativeSuccessful=true;
