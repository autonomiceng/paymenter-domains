<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-activation-test.php';$nativeSuccessful=false;
use App\Models\Setting;
use Paymenter\Extensions\Servers\Domains\Catalogue\{UpgradeGate,UpgradeComparison};
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade;

// The existing native fixture converts retained paid/pending/uncertain obligations using the bridge.
$before=UpgradeComparison::capture();$receipt=UpgradeGate::routeState();
OfferUpgrade::requireCompatible();
(new Domains)->upgraded('0.1.0');
same($receipt,UpgradeGate::routeState(),'Bridge-converted upgrade preserves its active receipt');
same($before,UpgradeComparison::capture(),'Bridge-converted upgrade preserves native and financial records');
$setting=Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key',UpgradeGate::KEY)->sole();
$active=$setting->value;
foreach(['legacy','paused'] as $mode){
    $setting->value=json_encode(['state'=>$mode,'generation'=>3]);$setting->save();$before=UpgradeComparison::capture();
    try{OfferUpgrade::requireCompatible();throw new LogicException('Unconverted retained upgrade accepted');}
    catch(DomainException $e){expect(str_contains($e->getMessage(),'bridge 0.1.0 at 891124421645'),'Refusal names the exact bridge to install first');}
    same($before,UpgradeComparison::capture(),'Unconverted refusal writes nothing');
}
$setting->value=$active;$setting->save();OfferUpgrade::requireCompatible();
$nativeSuccessful=true;
