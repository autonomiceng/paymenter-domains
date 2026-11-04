<?php
// Offline real Filament view using authenticated TEST enrollment and the retained NS16 catalogue.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/namesilo-test-catalogue-test.php';$nativeSuccessful=false;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Blade,Route};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarHistory,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Services\{BalanceReads,CatalogueAcceptances};
$out=$argv[1]??'';expect(is_dir($out)&&is_writable($out),'Output directory required');
$next['facts']=['com'=>$proof];config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$next]]);
$account=$account->fresh();$server->update(['name'=>'Synthetic NameSilo TEST']);
BalanceReads::record(CatalogueAcceptances::binding($account),new Paymenter\Extensions\Servers\Domains\Data\Money('9876.54','USD'),$clock->now(),'manual');
staffPanel();$panel=Filament::getCurrentPanel();$pages=[RegistrarAccount::class,RegistrarHistory::class,RegistrarSetup::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');$panel->viteTheme([])->theme(new HtmlString(''))->brandName('Paymenter');
$save=static function(string $name,$test)use($out,$css):void {
    app()->forgetInstance(\Filament\Navigation\NavigationManager::class);
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"/>').'"',$html);
    $html=str_replace('</head>','<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; font-src data:"><style>'.$css.'*{animation:none!important;transition:none!important}</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    writeRenderArtifact($out.'/'.$name.'.html',preg_replace('~<html\b[^>]*>~','<html lang="en" class="dark">',$html,1));
};
$writes->run($review('processing'),['enabled'=>false,'confirmed'=>true]);
$before=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->assertActionVisible('activate_test');$save('namesilo-before',$before);
$before->callAction('activate_test')->assertNotified('Orders resumed');
$after=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->assertActionDoesNotExist('activate_test')->assertSee('Active');$save('namesilo-after',$after);
echo "Real NameSilo TEST page rendered before and after the local Activate action.\n";$nativeSuccessful=true;
