<?php
// Staff sidebar, Domain monitoring Settings tab and notices, dark and light. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/staff-navigation-test.php';$nativeSuccessful=false;
use Illuminate\Support\Facades\{Blade,DB,Route};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{ActivationStatus,RegistrarAccount,RegistrarBalances,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource,PendingAdoptionResource,TldResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,MonitoringSettings};
use Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint;
use Paymenter\Extensions\Servers\Domains\Services\{DnsResolverSettings,WatchWork};
$out=$argv[1]??'';expect(is_dir($out),'Output directory required');
// The full menu: every Domains page that registers navigation, with its routes.
$more=[DomainResource::class,OperationResource::class,TldResource::class,PendingAdoptionResource::class];$panel->resources([...$resources,...$more])->pages([...$pages,RegistrarSetup::class,RegistrarAccount::class,RegistrarBalances::class,ActivationStatus::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($more,$panel){foreach([...$more,RegistrarSetup::class,RegistrarAccount::class,RegistrarBalances::class,ActivationStatus::class] as$r){$r::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');$panel->viteTheme([])->theme(new HtmlString(''))->brandName('Paymenter');
$save=function(string $name,$test)use($out,$css):void{
 app()->forgetInstance(\Filament\Navigation\NavigationManager::class);
 $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
 $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);$html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
 $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
 $html=str_replace('</head>','<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:"><style>'.$css.'*{animation:none!important;transition:none!important}</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
 foreach(['dark','light'] as $mode){writeRenderArtifact($out.'/staff-'.$mode.'-'.$name.'.html',preg_replace('~<html\b[^>]*>~','<html lang="en" class="'.$mode.'">',$html,1));}
};
// Overdue checks, left by the test: sidebar and notice on the list.
$save('monitoring-overdue',Livewire::actingAs($manager)->test(ListWatches::class));
// Checks failing for registry and DNS, and the queue timeout too short.
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$since]);
DB::table('domains_watch_work')->update(['attempts'=>1,'due_at'=>$since-86400]);foreach(['health_registry','health_watch_dns'] as $scope){DB::table('domains_watch_schedule')->updateOrInsert(['scope'=>$scope],['cursor'=>$since-86400]);}
$save('monitoring-failing',Livewire::actingAs($manager)->test(ListWatches::class));
config(['queue.connections.database.retry_after'=>60]);$save('monitoring-queue',Livewire::actingAs($manager)->test(ListWatches::class));
// Monitoring off (the default) on the list, and the Settings tab with and without a saved resolver.
app()->forgetInstance(WatchWork::class);$save('monitoring-off',Livewire::actingAs($manager)->test(ListWatches::class));
\App\Models\Setting::where('key',DnsResolverSettings::KEY)->delete();$save('settings-missing',Livewire::actingAs($manager)->test(MonitoringSettings::class));
$settings->save(DohEndpoint::URL,DohEndpoint::IP);$save('settings-saved',Livewire::actingAs($viewer)->test(MonitoringSettings::class));
putenv('SSLKEYLOGFILE=/synthetic-disabled');$save('settings-forced',Livewire::actingAs($manager)->test(MonitoringSettings::class));putenv('SSLKEYLOGFILE');
echo "Staff sidebar, Settings tab and monitoring notices rendered.\n";$nativeSuccessful=true;
