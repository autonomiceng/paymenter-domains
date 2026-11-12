<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$automaticSettingsGc=gc_enabled();gc_disable();
require __DIR__.'/Support/namesilo-settings-fixture.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
foreach(['domains_billing_enabled','domains_manual_renewals_enabled'] as $key){
    $server->settings()->updateOrCreate(['key'=>$key],['value'=>'1','type'=>'boolean','encrypted'=>false]);
}
// A real staff action, encrypted key rotation and fresh installed composition exercise the public send gates.
$rotationFailures=[];
foreach([false,true] as $on){
    foreach(['transfer','renewal','management'] as $feature){
        $writes->run($review('test_'.$feature),['accept'=>$on,'verified'=>true]);
    }
    $bindings=app(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class);
    $binding=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account->fresh());
    $revision=$bindings->credentialRevision($binding);
    $writes->run($review('configure'),['registrar'=>'namesilo','environment'=>'test',
        'credentials'=>['api_key'=>$on?'synthetic-rotation-on-key':'synthetic-rotation-off-key']]);
    same($binding->fingerprint,$account->fresh()->account_fingerprint,'Rotation retains the account identity');
    expect($revision!==$bindings->credentialRevision($binding),'Rotation actually changes the credential revision');
    expect(!$account->fresh()->processing_enabled,'Rotation pauses Orders before Activate');
    $page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
    $page->assertActionVisible('activate_test')->callAction('activate_test')->assertNotified('Orders resumed');
    expect($account->fresh()->processing_enabled,'Activate resumes Orders after key rotation');
    $rows=array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($account->fresh(),['label'=>'Active','reason'=>null]),null,'label');
    foreach(['transfer'=>'Transfers (Experimental)','renewal'=>'Renewals','management'=>'DNS, nameservers and contacts'] as $feature=>$label){
        $class='Paymenter\\Extensions\\Servers\\Domains\\Services\\EnomTest'.ucfirst($feature);
        foreach([
            'retains its displayed value'=>$rows[$label]['value']===($on?'On':'Off'),
            'retains its saved choice'=>$class::stored($binding)===$on,
            'keeps its sending gate consistent with the switch'=>$class::accepted($binding)===$on,
        ] as $check=>$passed){if(!$passed){$rotationFailures[]=$label.' '.($on?'On':'Off').' '.$check;}}
    }
    $gates=app(Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class)->acquisitionGates($account->fresh());
    if($gates!==['register'=>true,'transfer'=>$on]){$rotationFailures[]='Installed transfer writer follows the saved switch after rotation';}
    unset($page);gc_collect_cycles();gc_mem_caches();
}
expect($rotationFailures===[],implode(PHP_EOL,$rotationFailures));
if($automaticSettingsGc){gc_enable();}
$nativeSuccessful=true;
