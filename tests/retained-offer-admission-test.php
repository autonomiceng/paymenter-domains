<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-activation-test.php';$nativeSuccessful=false;
use App\Models\Setting;
use Paymenter\Extensions\Servers\Domains\Catalogue\CatalogueActivation;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\FreshInstallation;

// The native fixture loads the persisted result of the exact checked bridge.
$before=retainedOfferSnapshot(includeVerification:false);$receipt=CatalogueActivation::routeState();
FreshInstallation::requireCompatible();
(new Domains)->upgraded('0.1.0');
same($receipt,CatalogueActivation::routeState(),'Bridge-converted upgrade preserves its active receipt');
same($before,retainedOfferSnapshot(includeVerification:false),'Bridge-converted upgrade preserves native and financial records');
$setting=Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key',CatalogueActivation::KEY)->sole();
$active=$setting->value;
foreach(['legacy','paused'] as $mode){
    $setting->value=json_encode(['state'=>$mode,'generation'=>3]);$setting->save();$before=retainedOfferSnapshot();
    try{FreshInstallation::requireCompatible();throw new LogicException('Unconverted retained upgrade accepted');}
    catch(DomainException $e){expect(str_contains($e->getMessage(),'bridge 0.1.0 at 891124421645'),'Refusal names the exact bridge to install first');}
    same($before,retainedOfferSnapshot(),'Unconverted refusal writes nothing');
    try{(new Domains)->upgraded('0.1.0');throw new LogicException('Lifecycle accepted unconverted retained upgrade');}
    catch(DomainException $e){expect(str_contains($e->getMessage(),'bridge 0.1.0 at 891124421645'),'Lifecycle names the required bridge');}
    same($before,retainedOfferSnapshot(),'Lifecycle refusal precedes extension mutations');
}
$setting->value=$active;$setting->save();FreshInstallation::requireCompatible();
$nativeSuccessful=true;
