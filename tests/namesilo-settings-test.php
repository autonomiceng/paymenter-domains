<?php
// Native actions and rendered rows need the real panel and authenticated local enrollment.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/namesilo-test-activation-test.php';$nativeSuccessful=false;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
$writes->run($review('processing'),['enabled'=>false,'confirmed'=>true]);
staffPanel();$panel=Filament::getCurrentPanel();$pages=[RegistrarAccount::class,Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class,Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarHistory::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($pages,$panel){foreach($pages as $p){$p::registerRoutes($panel);}});
app('router')->getRoutes()->refreshNameLookups();
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->assertSuccessful();
$page->assertActionVisible('activate_test');
$page->callAction('activate_test')->assertNotified('Orders resumed');
expect($account->fresh()->processing_enabled,'Activate enables the authenticated TEST account');
same(1,$GLOBALS['enrollmentCalls'],'Activate is local and makes no provider request');
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
$failures=[];
foreach(['transfer','renewal','management'] as $feature){
    try{
        $action=setupAction('test_'.$feature,(int)$server->id);
        $page->assertActionVisible($action)->callAction($action)->assertNotified('Saved');
        $rows=array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($account->fresh(),['label'=>'Active','reason'=>null]),null,'label');
        $label=['transfer'=>'Transfers (experimental)','renewal'=>'Renewals','management'=>'DNS, nameservers and contacts'][$feature];
        same('Off',$rows[$label]['value'],$label.' can be turned off');
        $class='Paymenter\\Extensions\\Servers\\Domains\\Services\\EnomTest'.ucfirst($feature);
        expect(!$class::accepted(Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account->fresh())),$label.' Off closes the sending gate');
        $page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
        $page->callAction($action)->assertNotified('Saved');
        expect($class::accepted(Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account->fresh())),$label.' On opens its admitted TEST gate');
        $page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
    }catch(Throwable $e){$failures[]=$feature.': '.$e->getMessage();}
}
expect($failures===[],implode(PHP_EOL,$failures));
$nativeSuccessful=true;
