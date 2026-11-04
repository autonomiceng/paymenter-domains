<?php
// Real local enrollment and Filament rendering are needed to catch stale staff prompts.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/namesilo-settings-test.php';$nativeSuccessful=false;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,AccountChecklist};
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
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
