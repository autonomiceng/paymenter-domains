<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/retained-offer.php';
use App\Models\Setting;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade;
$saved=retainedOfferDatabase();
$row=Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key',UpgradeGate::KEY)->sole();
$row->value=json_encode([...$saved['receipt'],'contract'=>str_repeat('f',64)],JSON_THROW_ON_ERROR);$row->save();
$before=retainedOfferSnapshot();
foreach([OfferUpgrade::requireCompatible(...),static fn()=>(new Domains)->upgraded('0.1.0')] as $upgrade){
    try{$upgrade();throw new LogicException('Unknown active contract passed package admission');}
    catch(DomainException $error){same(OfferUpgrade::MESSAGE,$error->getMessage(),'Unknown contract returns to the reviewed bridge');}
    same($before,retainedOfferSnapshot(),'Unknown active contract refuses before migrations, templates or cleanup');
}
$nativeSuccessful=true;
