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
foreach(['active','paused','retired'] as $state){
    $probe=clone $w;$probe->state=$state;$facts=WatchFacts::for($probe,['keys'=>[],'summary'=>null]);$encoded=json_encode($facts);
    $expected=match($state){'paused'=>'Paused','retired'=>'Monitoring stopped; no further checks',default=>'Fills in by itself after the next check'};
    if(!str_contains($encoded,$expected)||$state!=='active'&&str_contains($encoded,'Fills in by itself')){$failures[]='Incorrect missing-fact guidance for '.$state;}
}
$probe=clone $w;$probe->setRelation('work',collect());
if(str_contains(json_encode(WatchFacts::for($probe,['keys'=>[],'summary'=>null])),'Fills in by itself')){$failures[]='Unqueued watch promises a next check';}
expect($failures===[],implode('; ',$failures));$nativeSuccessful=true;echo "Watch missing facts respect stopped, paused and queued work.\n";
