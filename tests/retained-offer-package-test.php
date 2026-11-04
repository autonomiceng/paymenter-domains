<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/retained-offer.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Capabilities,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

$saved=retainedOfferDatabase();$before=retainedOfferObligations();
$entry=new Domains;$entry->upgraded('0.1.0');
same($saved['receipt'],UpgradeGate::routeState(),'Current package preserves the pinned bridge active receipt');
same($before,retainedOfferObligations(),'Current package preserves every bridge native identity and frozen obligation');
$account=Account::where('registrar_key','enom')->sole();
same('10.40',Capabilities::routed()->holder(AccountBindings::binding($account),'com')->retail,'Bridge holder keeps annual retail without current public facts');
$entry->upgraded('0.1.0');$entry->installed();
same($saved['receipt'],UpgradeGate::routeState(),'Repeated upgrade and reinstall preserve the same receipt');
same($before,retainedOfferObligations(),'Repeated lifecycle never rewrites retained money or mappings');
$state=App\Models\Setting::where('key',UpgradeGate::KEY)->whereNull('settingable_type')->sole();
$state->value=json_encode(['state'=>'paused','generation'=>5]);$state->save();$before=retainedOfferObligations();
try{(new UpgradeGate)->capture();throw new LogicException('Final package still offers bridge conversion');}
catch(DomainException $e){expect(str_contains($e->getMessage(),'bridge 0.1.0 at 891124421645'),'Removed conversion points to the pinned bridge');}
same($before,retainedOfferObligations(),'Refused old conversion cannot change retained obligations');
$state->value=json_encode(['state'=>'legacy','generation'=>6]);$state->save();
$before=retainedOfferObligations();$binding=AccountBindings::binding($account);$now=new DateTimeImmutable('2026-10-18T12:00:00Z');
$prices=new Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue(new Paymenter\Extensions\Servers\Domains\Services\PricingRules);
$sellers=new Paymenter\Extensions\Servers\Domains\Services\SellerPolicies($prices,app(AccountBindings::class));
foreach([
 fn()=>$prices->renewal($binding->serverId,$binding->fingerprint,'com','USD',$now),
 fn()=>$prices->current($binding->serverId,$binding->fingerprint,'com','register',1,'USD',$now),
 fn()=>$sellers->displayCatalogues(['com']),
 fn()=>$sellers->selection('com','register',1,$now),
 fn()=>$sellers->admit([]),
] as $read){
 try{$read();throw new LogicException('Final package reached legacy policy');}
 catch(DomainException $e){same(Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE,$e->getMessage(),'Legacy policy entry points name the bridge');}
}
same($before,retainedOfferObligations(),'Retired policy reads preserve obligations');
$nativeSuccessful=true;
