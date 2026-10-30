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
$f=commerceFixture();extract($f);tldPanel();pinStaffClock($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::COSTS,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings);app()->instance(StaffSettingsWrites::class,$writer);
$key=Review::ticket($u->id,$t->server_id,null,'default_rule',Review::stamp($t->server_id,null));
// Saving the default publishes .com, which follows it; the fixed $1 prices are below cost.
same(['published'=>1,'failed'=>[]],$writer->run(new Review($u->id,$t->server_id,null,'default_rule',$key,'Update yearly prices for these TLDs'),['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'1','fixed_renew'=>'1','fixed_transfer'=>'1'],$now),'Default publishes');
\Paymenter\Extensions\Servers\Domains\Models\Domain::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder-placeholder.com','sld'=>'holder-placeholder','tld'=>'com','status'=>'active']);
$panel=Filament::getCurrentPanel();$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$render=static function($test,string $name,string $extra='')use($out,$css){
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',
        ['page'=>$test->instance(),'body'=>$test->html().($test->effects['partials']['action-modals.0']??'')]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.$extra.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    $state=$test->get('mountedActions.0.data')??[];unset($state['review_key']);
    $html=str_replace('</body>','<template id="fixture-action-state" data-state="'.e(json_encode($state,JSON_THROW_ON_ERROR)).'"></template></body>',$html);
    expect(!str_contains($html,'account-enom'),'No synthetic credential');writeRenderArtifact($out.'/'.$name.'.html',$html);
};
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSee(['TLD','Registrar account','Domains','Renewal price','.com']);$render($list,'tlds');
$view=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSee(['Prices by registrar account','History','More','Edit prices','Edit terms'],false)
    ->assertDontSee(['Publish prices','Use default pricing','Edit default pricing'],false);$render($view,'tld-revisions');
// The header More menu, opened: its panel is hidden until clicked, so show it for the capture.
$render($view,'tld-more-menu','.fi-ac .fi-dropdown-panel{display:block!important;position:absolute;right:0;z-index:50}');
$render(Livewire::actingAs($u)->test(RegistrarSetup::class),'setup-both');$render(Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id]),'setup-account');$strings=[];
// TLD page actions, then the account actions this Enom TEST fixture shows. Live and test renewal or DNS changes
// and NameSilo enrollment do not appear for it: their server config is off.
$tldActions=['lifecycle','override','tld_settings','seller'];
$hiddenAccountActions=['live_management','live_renewal','test_management','test_renewal','enroll_sandbox'];
$accountActions=array_values(array_diff(staffSetupActions(),$hiddenAccountActions));
same([],array_values(array_diff(array_keys(Review::ACTIONS),$tldActions,$accountActions,$hiddenAccountActions,staffNoButtonActions())),'Every settings action is placed');
foreach(array_intersect_key(Review::ACTIONS,array_flip([...$tldActions,...$accountActions])) as $action=>$label){
    app()->instance(StaffSettingsWrites::class,in_array($action,['configure','probe'],true)?new StaffSettingsWrites($native,$sellers,$bindings,true):$writer);
    $setup=in_array($action,$accountActions,true);$page=Livewire::actingAs($u)->test($setup?RegistrarAccount::class:ViewTld::class,$setup?['account'=>$t->server_id]:['record'=>$t->id]);
    $name=$setup?setupAction($action,$t->server_id):$action;$page->mountAction($name)->assertActionMounted($name)->call('$refresh');$render($page,'modal-'.$action);
    $strings[$action]=['label'=>$label,'description'=>SettingsActions::description($action)];
}
app()->instance(StaffSettingsWrites::class,$writer);
// Edit prices with the live preview: own prices, with one below-cost row, then the account default.
// Dialog content renders in the action-modals partial, not the page body.
$sees=static function($page,array $texts):void{$html=$page->effects['partials']['action-modals.0']??'';foreach($texts as $text){expect(str_contains($html,$text),'Dialog shows: '.$text);}};
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('override')
    ->setActionData(['pricing'=>'custom','mode'=>'markup_amount','markup'=>'1.20','round_up'=>'half'])->call('$refresh');
$sees($page,['Customer pays: cost + $1.20, rounded up to the next $0.50','After saving','$7.50','$22.50']);$render($page,'modal-override-preview');
$page->setActionData(['pricing'=>'custom','mode'=>'fixed','fixed_register'=>'5','fixed_renew'=>'7','fixed_transfer'=>'9'])->call('$refresh');
$sees($page,['$5.00<div style="font-size:.75rem;color:rgb(217 119 6)">Below cost: not sold','Below cost: still billed']);$render($page,'modal-override-below-cost');
$page->setActionData(['pricing'=>'default'])->call('$refresh');$sees($page,['Customer pays: $1.00 a year to register']);$render($page,'modal-override-default');
$page->setActionData(['pricing'=>'custom','mode'=>'markup_amount','markup'=>'1.20','round_up'=>'half','reason'=>'Publish reviewed prices'])->callMountedAction()
    ->assertNotified(\Filament\Notifications\Notification::make()->success()->title('Prices published'));
same('7.50',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'Saving from the dialog publishes');
$render(Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id]),'tld-published');
// Out of date costs: the preview says so and saving changes nothing.
pinStaffClock($now->modify('+2 days'));
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('override')->setActionData(['pricing'=>'custom','mode'=>'markup_percent','markup'=>'40','round_up'=>'none'])->call('$refresh');
$sees($page,['Some costs are out of date','Cost out of date']);$render($page,'modal-override-stale');
$page->setActionData(['reason'=>'Try to publish with out of date costs'])->callMountedAction()
    ->assertNotified(\Filament\Notifications\Notification::make()->danger()->title('Change refused')->body('Costs are missing or out of date. Sync prices on the account page, then save again. Nothing was changed.'));
$render($page,'refusal-stale-costs');
pinStaffClock($now);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('override');
\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->increment('revision');
$page->setActionData(['reason'=>'Refuse stale publication after seller change'])->callMountedAction();$render($page,'refusal-offline');
// Default pricing on the account page names the TLDs that follow it.
$account=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->assertSee(['Default pricing','Customer pays','No TLDs use it yet.'],false);
$writer->run(new Review($u->id,$t->server_id,$t->id,'remove_override',Review::ticket($u->id,$t->server_id,$t->id,'remove_override',Review::stamp($t->server_id,$t->id)),'Follow the account default again'),[],$now);
$account=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->assertSee(['Used by 1 TLD: .com'],false);$render($account,'account-default-pricing');
$account->mountAction(setupAction('default_rule',$t->server_id))->call('$refresh');$sees($account,['1 TLD uses the account default: .com. Saving publishes its new prices.']);$render($account,'modal-default_rule-followers');
writeRenderArtifact($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
same(15,count($strings),'Fifteen settings modals rendered');
echo "Native TLD list/detail/revisions, account page, fifteen modals, price preview and refusals rendered.\n";$nativeSuccessful=true;
