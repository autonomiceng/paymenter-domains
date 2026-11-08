<?php
// Real encrypted WatchName relations and staff facts; no network or full list fixture is needed.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
$f=noticeFixture();extract($f);\Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema::install();staffPanel();Auth::setUser(staffUser(array_keys(Staff::permissions())));
$resource=\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::class;
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->resources([$resource]);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>$resource::registerRoutes($panel));app('router')->getRoutes()->refreshNameLookups();
$w=WatchName::findOrFail($id);$failures=[];
$paused=clone $w;$paused->state='paused';$public=new \Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation(['check_kind'=>'points','started_at'=>1700000000,'completed_at'=>1700000001,'result'=>'complete','partial'=>false,'valid_until'=>1700000300,'facts'=>['queries'=>[]]]);
$paused->setRelation('points',$public);
$facts=WatchFacts::for($paused,['keys'=>[],'summary'=>null]);
expect(isset($facts['Evidence']['Public DNS']),'Paused empty answers retain Public DNS evidence row');
expect(str_contains((string)$facts['Evidence']['Public DNS']['sub'],'2023-11-14'),'The evidence row keeps its own public observation instant');

foreach(['active','paused','retired'] as $state){
    $probe=clone $w;$probe->state=$state;$facts=WatchFacts::for($probe,['keys'=>[],'summary'=>null]);$encoded=json_encode($facts);
    $expected=match($state){'paused'=>'Paused','retired'=>'Monitoring stopped; no further checks',default=>'Fills in by itself after the next check'};
    if(!str_contains($encoded,$expected)||$state!=='active'&&str_contains($encoded,'Fills in by itself')){$failures[]='Incorrect missing-fact guidance for '.$state;}
}
$probe=clone $w;$probe->setRelation('work',collect());
if(str_contains(json_encode(WatchFacts::for($probe,['keys'=>[],'summary'=>null])),'Fills in by itself')){$failures[]='Unqueued watch promises a next check';}

// The list uses its real native column renderers, not the detail facts above.
$panel->resources([\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::class]);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::registerRoutes($panel));app('router')->getRoutes()->refreshNameLookups();
$panel->viteTheme([])->theme(new \Illuminate\Support\HtmlString(''));
foreach(['paused'=>'Paused','retired'=>'Stopped']as$state=>$expected){
 \Illuminate\Support\Facades\DB::table('domains_watch_names')->where('id',$id)->update(['state'=>$state]);
 $list=\Livewire\Livewire::test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ListWatches::class);
 $record=$w->fresh();
 same($expected,$list->instance()->getTable()->getColumn('status')->record($record)->getState(),'The row names its monitoring state');
 $list->assertSee($expected);
 foreach(['registration','delegation.dns','last_check']as$name){$column=$list->instance()->getTable()->getColumn($name)->record($record);
  // Status says Paused or Stopped once per row (ui-r15); the fact cells stay empty instead of repeating it.
  if(filled($column->getState())){$failures[]=$state.' '.$name.' still has Missing or repeats the row state';}
  if(str_contains((string)$column->getDescriptionBelow(),'Fills in by itself')){$failures[]=$state.' '.$name.' promises checks';}
 }
 $html=\Illuminate\Support\Facades\Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$list->instance(),'body'=>$list->html()]);
 $html=preg_replace('~<script\b[^>]*>.*?</script>|<link\b[^>]*>~si','',$html);
 writeRenderArtifact((getenv('R14_RENDER_DIR')?:sys_get_temp_dir()).'/r14-watchlist-'.$state.'.html',str_replace('</head>','<style>'.file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css').'</style>'.view('domains::partials.staff-styles')->render().'</head>',$html));
}
expect($failures===[],implode('; ',$failures));$nativeSuccessful=true;echo "Watch missing facts respect stopped, paused and queued work.\n";
