<?php
// Registrars list and account pages for visual review, dark and light. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/registrars-page-test.php';
$nativeSuccessful=false;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{PublicSuffixList as PslPage,RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Services\{BalanceReads,CatalogueAcceptances,StaffSettingsReview};
$out=$argv[1]??'';expect(is_dir($out)&&is_writable($out),'Output directory required');
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');Filament::getCurrentPanel()->viteTheme([])->theme(new HtmlString(''))->brandName('Paymenter');
$save=function(string $name,$test)use($out,$css):void{
    app()->forgetInstance(\Filament\Navigation\NavigationManager::class);
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:"><style>'.$css.'*{animation:none!important;transition:none!important}</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    foreach(['dark','light'] as $mode){writeRenderArtifact($out.'/'.$name.'-'.$mode.'.html',preg_replace('~<html\b[^>]*>~','<html lang="en" class="'.$mode.'">',$html,1));}
};
// A pending domain without expiry, alongside the active domain's open service alert.
$pending=\Paymenter\Extensions\Servers\Domains\Models\Domain::where('sld','registrars-due-placeholder')->sole()->replicate();
$pending->fill(['domain'=>'pending-alert.example.test','sld'=>'pending-alert','tld'=>'example.test','service_id'=>9001,
    'account_fingerprint'=>$enom->fresh()->account_fingerprint,'status'=>'registration_pending','provider_expires_at'=>null,'last_synced_at'=>null]);$pending->save();
\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($pending,'unknown_expiry',time());
// Show a read balance one cent below Due in 30 days, after the test's failed read.
$clock->instant=$clock->instant->modify('+2 minutes');BalanceReads::record(CatalogueAcceptances::binding($enom),new Money('7.99','USD'),$clock->now(),'manual');
$save('list',Livewire::actingAs($staff)->test(RegistrarSetup::class));
// Accounts render for staff with every setup permission and the public suffix list, so all buttons show.
Paymenter\Extensions\Servers\Domains\Services\PslSchema::install();Paymenter\Extensions\Servers\Domains\Services\PslReviewSchema::install();
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::class,new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites(app(\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class),app(\Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class),app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class),true));
$full=staffUser([Staff::VIEW,Staff::POLICY,...array_map([StaffSettingsReview::class,'permission'],array_keys(StaffSettingsReview::ACTIONS)),PslPage::VIEW,PslPage::MANAGE]);
foreach(['account-enom-test'=>$enom->server_id,'account-namesilo-test'=>$silo->server_id,'account-not-connected'=>$empty->id] as $name=>$id){$save($name,Livewire::actingAs($full)->test(RegistrarAccount::class,['account'=>$id]));}
// Domain monitoring on with a queue timeout below 180 seconds: the Background worker row says so.
app()->instance(Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new Paymenter\Extensions\Servers\Domains\Services\WatchWork(new Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader(static fn()=>[]),new Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(static fn()=>null),true));
config(['queue.default'=>'database','queue.connections.database.retry_after'=>60]);$save('account-queue',Livewire::actingAs($full)->test(RegistrarAccount::class,['account'=>$enom->server_id]));
echo "Registrars list and account pages rendered\n";$nativeSuccessful=true;
