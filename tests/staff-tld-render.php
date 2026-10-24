<?php
// Native static panel fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites};
$out=$argv[1]??'';expect(is_dir($out)&&is_writable($out),'Output directory required');
$f=commerceFixture();extract($f);tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::COSTS,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings);app()->instance(StaffSettingsWrites::class,$writer);
$key=Review::ticket($u->id,$t->server_id,$t->id,'default_rule',Review::stamp($t->server_id,$t->id));
$writer->run(new Review($u->id,$t->server_id,$t->id,'default_rule',$key,'Review annual retail for saved catalogue'),['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'1','fixed_renew'=>'1','fixed_transfer'=>'1']);
$key=Review::ticket($u->id,$t->server_id,$t->id,'publish',Review::stamp($t->server_id,$t->id));
$writer->run(new Review($u->id,$t->server_id,$t->id,'publish',$key,'Publish reviewed annual retail revision'),[],$now);
\Paymenter\Extensions\Servers\Domains\Models\Domain::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder-placeholder.com','sld'=>'holder-placeholder','tld'=>'com','status'=>'active']);
$panel=Filament::getCurrentPanel();$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$render=static function($test,string $name)use($out,$css){
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',
        ['page'=>$test->instance(),'body'=>$test->html().($test->effects['partials']['action-modals.0']??'')]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    $state=$test->get('mountedActions.0.data')??[];unset($state['review_key']);
    $html=str_replace('</body>','<template id="fixture-action-state" data-state="'.e(json_encode($state,JSON_THROW_ON_ERROR)).'"></template></body>',$html);
    expect(!str_contains($html,'account-enom'),'No synthetic credential');writeRenderArtifact($out.'/'.$name.'.html',$html);
};
$render(Livewire::actingAs($u)->test(ListTlds::class),'tlds');$render(Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id]),'tld-revisions');
$render(Livewire::actingAs($u)->test(RegistrarSetup::class),'setup-both');$render(Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id]),'setup-account');$strings=[];
foreach(Review::ACTIONS as $action=>$label){
    app()->instance(StaffSettingsWrites::class,in_array($action,['configure','probe'],true)?new StaffSettingsWrites($native,$sellers,$bindings,true):$writer);
    $setup=in_array($action,['configure','probe','acceptance','import','history','processing','renewal_billing','delivery_review','alert_review'],true);$page=Livewire::actingAs($u)->test($setup?RegistrarAccount::class:ViewTld::class,$setup?['account'=>$t->server_id]:['record'=>$t->id]);
    $name=$setup?\Filament\Actions\Testing\TestAction::make($action.'_'.$t->server_id)->schemaComponent('account_'.$t->server_id,'content'):$action;$page->mountAction($name)->assertActionMounted($name)->call('$refresh');$render($page,'modal-'.$action);
    $strings[$action]=['label'=>$label,'description'=>SettingsActions::description($action)];
}
app()->instance(StaffSettingsWrites::class,$writer);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('publish');
\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->increment('revision');
$page->setActionData(['reason'=>'Refuse stale publication after seller change'])->callMountedAction();$render($page,'refusal-offline');
writeRenderArtifact($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Native TLD list/detail/revisions, setup, ten modals and refusal rendered.\n";$nativeSuccessful=true;
