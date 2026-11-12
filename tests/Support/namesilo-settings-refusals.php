<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$automaticWordingGc=gc_enabled();gc_disable();
require __DIR__.'/namesilo-settings-fixture.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
// Edit API credentials: a refused key, an unclear reply and no reply each keep their own words, and nothing is saved.
$revision=app(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->credentialRevision(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account->fresh()));
foreach([
    'refused key'=>['ok','wrong-key','NameSilo refused this API key. Nothing was saved. Check the key, its account type and any IP limit set on it.'],
    'unclear reply'=>['missing_balance','synthetic-unclear-key','Registrar key could not be confirmed. Nothing was saved. Check the API details and try again.'],
    'IP limit refusal'=>['code_113','synthetic-ip-key','NameSilo refused this API key. Nothing was saved. Check the key, its account type and any IP limit set on it.'],
    'card profile refusal'=>['code_117','synthetic-card-key','Registrar key could not be confirmed. Nothing was saved. Check the API details and try again.'],
    'funds refusal'=>['code_119','synthetic-funds-key','Registrar key could not be confirmed. Nothing was saved. Check the API details and try again.'],
    'request refusal'=>['code_108','synthetic-request-key','Registrar key could not be confirmed. Nothing was saved. Check the API details and try again.'],
    'no reply'=>['throw','synthetic-silent-key','Registrar key could not be confirmed. Nothing was saved. Check the API details and try again.'],
] as $case=>[$mode,$key,$words]){
    if(!in_array($case,$refusalCases,true)){continue;}
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
