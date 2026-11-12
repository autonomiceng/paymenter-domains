<?php
// Native actions and rendered rows need the real panel and authenticated local enrollment.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Collect completed Filament trees at the explicit boundaries below, while keeping action mounts fresh.
$automaticSettingsGc=gc_enabled();gc_disable();
require __DIR__.'/Support/namesilo-settings-fixture.php';
gc_collect_cycles();gc_mem_caches();
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
$writes->run($review('processing'),['enabled'=>false,'confirmed'=>true]);
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->assertSuccessful();
$page->assertActionVisible('activate_test');
$page->callAction('activate_test')->assertNotified('Orders resumed');
expect($account->fresh()->processing_enabled,'Activate enables the authenticated TEST account');
same(1,$GLOBALS['enrollmentCalls'],'Activate is local and makes no provider request');
// Every action still gets a fresh native mount; release completed cyclic Filament trees.
unset($page);gc_collect_cycles();gc_mem_caches();
// An unsaved TEST renewal choice must agree with the native billing switches.
$binding=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account->fresh());
$rows=array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($account->fresh(),['label'=>'Active','reason'=>null]),null,'label');
same('Off',$rows['Renewals']['value'],'A fresh TEST account with billing Off displays Renewals Off');
foreach([[false,false],[true,false],[false,true],[true,true]] as [$scheduled,$manual]){
    foreach(['domains_billing_enabled'=>$scheduled,'domains_manual_renewals_enabled'=>$manual] as $key=>$on){
        $server->settings()->updateOrCreate(['key'=>$key],['value'=>$on?'1':'0','type'=>'boolean','encrypted'=>false]);
    }
    same($scheduled&&$manual,Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::accepted($binding),
        'An unsaved TEST renewal permission requires both billing switches');
}
// Start the existing toggle/rotation cases from an explicitly reviewed On choice.
$writes->run($review('test_renewal'),['accept'=>true,'verified'=>true]);
$failures=[];
foreach(['transfer','renewal','management'] as $feature){
    try{
        $page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
        $action=setupAction('test_'.$feature,(int)$server->id);
        $page->assertActionVisible($action)->callAction($action)->assertNotified('Saved');
        $rows=array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($account->fresh(),['label'=>'Active','reason'=>null]),null,'label');
        $label=['transfer'=>'Transfers (Experimental)','renewal'=>'Renewals','management'=>'DNS, nameservers and contacts'][$feature];
        same('Off',$rows[$label]['value'],$label.' can be turned off');
        $class='Paymenter\\Extensions\\Servers\\Domains\\Services\\EnomTest'.ucfirst($feature);
        expect(!$class::accepted(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account->fresh())),$label.' Off closes the sending gate');
        unset($page);gc_collect_cycles();gc_mem_caches();
        $page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
        $page->callAction($action)->assertNotified('Saved');
        expect($class::accepted(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account->fresh())),$label.' On opens its admitted TEST gate');
    }catch(Throwable $e){$failures[]=$feature.': '.$e->getMessage();}
    unset($page);gc_collect_cycles();gc_mem_caches();
}
expect($failures===[],implode(PHP_EOL,$failures));
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
