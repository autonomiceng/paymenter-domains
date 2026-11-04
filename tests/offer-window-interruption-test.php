<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/retained-offer.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Domains;
retainedOfferDatabase();
$setting=App\Models\Setting::where('key',UpgradeGate::KEY)->whereNull('settingable_type')->sole();
$setting->value=json_encode(['state'=>'paused','generation'=>5]);$setting->save();
DB::table('domains_observation_queues')->update(['catalogue_claim'=>str_repeat('a',32),'catalogue_lease'=>1]);
$claims=DB::table('domains_observation_queues')->orderBy('id')->get()->toJson();$before=retainedOfferObligations();
try{(new Domains)->upgraded('0.1.0');throw new LogicException('Interrupted bridge conversion accepted by final package');}
catch(DomainException $e){expect(str_contains($e->getMessage(),'bridge 0.1.0 at 891124421645'),'Interrupted maintenance returns to the exact bridge');}
same($claims,DB::table('domains_observation_queues')->orderBy('id')->get()->toJson(),'Refusal never clears expired or interrupted claims');
same($before,retainedOfferObligations(),'Interrupted conversion refusal preserves frozen obligations');
same('paused',UpgradeGate::initializationState()['state'],'Refusal never seeds active state');
$nativeSuccessful=true;
