<?php
// Real local enrollment and Filament rendering are needed to catch stale staff prompts.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/namesilo-settings-test.php';$nativeSuccessful=false;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,AccountChecklist};
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
$page->assertSee('Customer pays')->assertSee('Card fee')->assertSee('2.9% + $0.30 per payment');
foreach(['customer_pays','card_fee'] as $control){
    $action=Filament\Actions\Testing\TestAction::make('default_pricing_'.$control)->schemaComponent('account_'.$server->id,'content');
    $page->assertActionVisible($action)->mountAction($action)->assertActionMounted($action);
    same('Edit default pricing?',$page->instance()->getMountedAction()->getModalHeading(),'Pricing rows open the shared reviewed pricing form');$page->unmountAction();
}
$page->assertActionDoesNotExist('accept_namesilo_account');
$page->mountAction(setupAction('configure',(int)$server->id));
$page->setActionData(['registrar'=>'namesilo']);
$description=$page->instance()->getMountedAction()->getModalDescription();
expect(str_contains($description,'one balance read')&&!str_contains($description,"can't be connected"),'Connection dialog explains the actual key check');
$page->unmountAction();
$rows=array_column(AccountChecklist::setup($account->fresh(),['label'=>'Active','reason'=>null]),null,'label');
same(['Not supported',null],[$rows['Late renewal']['value'],$rows['Late renewal']['action']],'Unsupported late renewal has no switch');
$page->assertSee('Free when supported by the TLD.')->assertSee('DNS records and DNSSEC are not offered for NameSilo.');
same(1,$GLOBALS['enrollmentCalls'],'Viewing and local settings never probe the provider');
$nativeSuccessful=true;
