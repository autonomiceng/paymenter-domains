<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchRows};
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchDnsObservation};
use Paymenter\Extensions\Servers\Domains\Presentation\State;
extract(dnsFixture());staffPanel();Auth::setUser(staffUser(array_keys(Staff::permissions())));
$resource=\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::class;
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->resources([$resource]);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>$resource::registerRoutes($panel));app('router')->getRoutes()->refreshNameLookups();
$stopped=now()->subDays(2);$returned=$stopped->copy()->addDay();
foreach(['staff_retire'=>$stopped,'discovery_returned'=>$returned] as $event=>$at){WatchAudit::create(['subject_type'=>'watch','subject_id'=>$watch->id,'user_id'=>$owner->id,'event'=>$event,'reason'=>$event,'before_revision'=>'1','after_revision'=>'2','data'=>[],'created_at'=>$at]);}
$history=WatchFacts::history($watch);
$failures=[];
if(strtotime($history[0]['time'])!==$returned->timestamp||$history[0]['by']!=='System'){$failures[]='The resumed monitoring event must be latest and attributed to the system';}
$at=time()-86400;
$watch->setRelation('points',new WatchDnsObservation(['watch_id'=>$watch->id,'user_id'=>$owner->id,'watch_revision'=>$watch->revision,'check_kind'=>'points','started_at'=>$at,'result'=>'partial','partial'=>true,'facts'=>['queries'=>[['name'=>$watch->canonical_name,'type'=>'A','state'=>'unknown','records'=>[]]]]]));
$facts=WatchFacts::for($watch,['keys'=>[],'summary'=>null]);$value=WatchRows::value($facts['Evidence']['Public DNS']);
if($value->state!==State::CouldntRead){$failures[]='A retained failed public read must not become an unread state';}
expect($failures===[],implode('; ',$failures));
$nativeSuccessful=true;echo "Monitoring lifecycle and failed public evidence passed.\n";
