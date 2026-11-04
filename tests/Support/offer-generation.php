<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
/** Supersede an already authentic fresh/bridge activation to exercise in-flight fencing.
 * Never manufacture activation or bypass the installed route/contract checks.
 */
function advanceOfferGeneration(): int
{
    $state=\Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::routeState();
    same('active',$state['state'],'Generation race requires an actual lifecycle/checked activation');
    $row=\App\Models\Setting::whereNull('settingable_type')->whereNull('settingable_id')
        ->where('key',\Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::KEY)->sole();
    $row->value=json_encode([...$state,'generation'=>$state['generation']+1],JSON_THROW_ON_ERROR);$row->save();
    return $state['generation']+1;
}
