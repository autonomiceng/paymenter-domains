<?php
// Real local enrollment and Filament rendering are needed to catch stale staff prompts.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The included native actions collect completed trees explicitly; avoid scanning live forms on each request.
$automaticWordingGc=gc_enabled();gc_disable();
// Use the native enrollment witness; settings toggles/rotations are independently
// registered in namesilo-settings-test.php and need not remount every form here.
require __DIR__.'/namesilo-test-activation-test.php';$nativeSuccessful=false;
Illuminate\Support\Facades\Schema::create('cache',static function($t):void {
    $t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');
});
staffPanel();$panel=Filament\Facades\Filament::getCurrentPanel();
$pages=[Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,
    Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class,
    Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarHistory::class];
$panel->pages($pages);
Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($pages,$panel):void {
    foreach($pages as $p){$p::registerRoutes($panel);}
});
app('router')->getRoutes()->refreshNameLookups();
// Preserve the original three-authentication witness using real key rotation,
// without repeating settings-test's unrelated action mounts and assertions.
foreach(['synthetic-rotation-off-key','synthetic-rotation-on-key'] as $key){
    $writes->run($review('configure'),['registrar'=>'namesilo','environment'=>'test','credentials'=>['api_key'=>$key]]);
}

use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,AccountChecklist};
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
// J8: the card fee and Default pricing are set only in What you sell; the account page shows both, as in the approved mock, and links there.
$page->assertSee('Customer pays')->assertSee('Card fee')->assertSee('2.9% + $0.30 per payment');
$page->assertActionDoesNotExist('accept_namesilo_account');
$page->mountAction(setupAction('configure',(int)$server->id));
$page->setActionData(['registrar'=>'namesilo']);
$description=$page->instance()->getMountedAction()->getModalDescription();
expect(str_contains($description,'one balance read')&&!str_contains($description,"can't be connected"),'Connection dialog explains the actual key check');
$page->unmountAction();gc_collect_cycles();gc_mem_caches();
$rows=array_column(AccountChecklist::setup($account->fresh(),['label'=>'Active','reason'=>null]),null,'label');
same(['Not supported',null],[$rows['Late renewal']['value'],$rows['Late renewal']['action']],'Unsupported late renewal has no switch');
$page->assertSee('Free when supported by the TLD.')->assertSee('DNS records and DNSSEC are not offered for NameSilo.');
// The included fixture authenticates the initial key and both credential rotations.
same(3,$GLOBALS['enrollmentCalls'],'Viewing and local settings never probe the provider');
if($automaticWordingGc){gc_enable();}
$nativeSuccessful=true;
