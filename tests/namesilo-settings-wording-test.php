<?php
// Real local enrollment and Filament rendering are needed to catch stale staff prompts.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The included native actions collect completed trees explicitly; avoid scanning live forms on each request.
$automaticWordingGc=gc_enabled();gc_disable();
require __DIR__.'/namesilo-settings-test.php';$nativeSuccessful=false;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,AccountChecklist};
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
$page->assertSee('Customer pays')->assertSee('Card fee')->assertSee('2.9% + $0.30 per payment');
foreach(['customer_pays','card_fee'] as $control){
    $action=Filament\Actions\Testing\TestAction::make('default_pricing_'.$control)->schemaComponent('account_'.$server->id,'content');
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
// The included fixture authenticates the initial key and both credential rotations.
same(3,$GLOBALS['enrollmentCalls'],'Viewing and local settings never probe the provider');
// Edit API credentials: a refused key, an unclear reply and no reply each keep their own words, and nothing is saved.
$revision=app(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->credentialRevision(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account->fresh()));
foreach([
    'refused key'=>['ok','wrong-key','NameSilo refused this API key. Nothing was saved. Check the key, its account type and any IP limit set on it.'],
    'unclear reply'=>['missing_balance','synthetic-unclear-key','Registrar key could not be confirmed. Nothing was saved. Check the API details and try again.'],
    'no reply'=>['throw','synthetic-silent-key','Registrar key could not be confirmed. Nothing was saved. Check the API details and try again.'],
] as $case=>[$mode,$key,$words]){
    $GLOBALS['enrollmentMode']=$mode;$calls=$GLOBALS['enrollmentCalls'];
    $page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
    $page->mountAction(setupAction('configure',(int)$server->id))
        ->setActionData(['registrar'=>'namesilo','environment'=>'test','api_key'=>$key,'reason'=>'Check the saved key'])
        ->callMountedAction()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save")->body($words));
    same($calls+1,$GLOBALS['enrollmentCalls'],ucfirst($case).' sends one key check');
    same($revision,app(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->credentialRevision(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account->fresh())),ucfirst($case).' keeps the saved key');
    unset($page);gc_collect_cycles();gc_mem_caches();
}
$GLOBALS['enrollmentMode']='ok';
if($automaticWordingGc){gc_enable();}
$nativeSuccessful=true;
