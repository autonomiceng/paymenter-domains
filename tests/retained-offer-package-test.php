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
$nativeSuccessful=true;
