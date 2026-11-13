<?php
// Real local enrollment and Filament rendering are needed to catch stale staff prompts.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The included native actions collect completed trees explicitly; avoid scanning live forms on each request.
$automaticWordingGc=gc_enabled();gc_disable();
require __DIR__.'/Support/namesilo-settings-fixture.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,AccountChecklist};
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
$page->assertSee('Customer pays')->assertSee('Card fee')->assertSee('2.9% + $0.30 per payment');
foreach(['customer_pays','card_fee'] as $control){
    $action=Filament\Actions\Testing\TestAction::make('default_pricing_'.$control)->schemaComponent('sheet','content');
    $page->assertActionVisible($action)->mountAction($action)->assertActionMounted($action);
    same('Edit default pricing?',$page->instance()->getMountedAction()->getModalHeading(),'Pricing rows open the shared reviewed pricing form');$page->unmountAction();gc_collect_cycles();gc_mem_caches();
}
$page->assertActionDoesNotExist('accept_namesilo_account');
$page->mountAction(setupAction('configure',(int)$server->id));
$page->setActionData(['registrar'=>'namesilo']);
$description=$page->instance()->getMountedAction()->getModalDescription();
expect(str_contains($description,'one balance read')&&!str_contains($description,"can't be connected"),'Connection dialog explains the actual key check');
$page->unmountAction();gc_collect_cycles();gc_mem_caches();
$rows=array_column(AccountChecklist::setup($account->fresh(),['label'=>'Active','reason'=>null]),null,'label');
same(['Not supported',null],[$rows['Late renewal']['value'],$rows['Late renewal']['action']],'Unsupported late renewal has no switch');
$page->assertSee('Free when supported by the TLD.')->assertSee('DNS records and DNSSEC are not offered for NameSilo.');
// Credential rotation is covered by namesilo-settings-test.php; viewing does not probe again.
same(1,$GLOBALS['enrollmentCalls'],'Viewing and local settings never probe the provider');
if($automaticWordingGc){gc_enable();}
$nativeSuccessful=true;
