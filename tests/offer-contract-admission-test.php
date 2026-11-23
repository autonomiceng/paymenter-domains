<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/catalogue-snapshot.php';
use App\Models\Setting;
use Paymenter\Extensions\Servers\Domains\Catalogue\CatalogueActivation;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\FreshInstallation;
(new Domains)->installed();
\Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
same(FreshInstallation::CONTRACT,\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::contract()['id'],'Installed caller contract remains consistent with package admission');
$row=Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key',CatalogueActivation::KEY)->sole();
$row->value=json_encode([...CatalogueActivation::routeState(),'contract'=>str_repeat('f',64)],JSON_THROW_ON_ERROR);$row->save();
$before=retainedOfferSnapshot();
foreach([FreshInstallation::requireCompatible(...),static fn()=>(new Domains)->upgraded('0.1.0')] as $upgrade){
    try{$upgrade();throw new LogicException('Unknown active contract passed package admission');}
    catch(DomainException $error){same(FreshInstallation::MESSAGE,$error->getMessage(),'Unknown contract refuses activation');}
    same($before,retainedOfferSnapshot(),'Unknown active contract refuses before migrations, templates or cleanup');
}
$nativeSuccessful=true;
