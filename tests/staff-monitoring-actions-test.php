<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff monitoring over synthetic local evidence.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
extract($checked());
// Pause and resume run without a dialog; Check now queues; stale pages refuse.
$stale=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]);DB::table('domains_watch_names')->where('id',$id)->increment('revision');
$stale->call('refreshFacts')->callAction('pause')->assertNotified('This domain changed. Reload the page and try again.');same('active',WatchName::find($id)->state,'Stale page changes nothing');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('pause')->assertNotified('Monitoring paused');same('paused',WatchName::find($id)->state,'Pause saves');
$paused=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertActionHidden('pause')->assertActionHidden('read')->assertSee('Paused')->assertDontSee('Next check');
same('Resume monitoring',$paused->instance()->getAction('resume')->getLabel(),'Resume label');$paused->callAction('resume')->assertNotified('Monitoring resumed');same('active',WatchName::find($id)->state,'Resume saves');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('note',['note'=>'Synthetic staff note'])->assertNotified('Saved');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSeeInOrder(['History','Note','Synthetic staff note','Monitoring resumed','Monitoring paused'])->assertDontSee('No history yet');
$rows=WatchFacts::history(WatchName::find($id));same(['Note','Monitoring resumed','Monitoring paused'],array_column($rows,'event'),'History events newest first');
// Pausing turns alerts off on this page and keeps the customer's saved choice for when monitoring resumes.
$alerts=fn()=>array_intersect_key(WatchFacts::for(WatchName::find($id))['Checks']['Monitoring alerts'],['value'=>1,'sub'=>1]);
$prefs=fn()=>\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::read($uid,'example.com');$saved=$prefs();
same([true,true],[$saved['account']['enabled'],$saved['target']['enabled']],'The customer turned alerts on');
same(['value'=>'On','sub'=>null],$alerts(),'Active monitoring shows alerts On');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('pause')->assertNotified('Monitoring paused');
same(['value'=>'Off','sub'=>'Paused. Alerts resume with monitoring.'],$alerts(),'Paused monitoring shows alerts Off');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSee('Paused. Alerts resume with monitoring.');
same($saved,$prefs(),'Pausing keeps the customer alert choice');
// Resume while the hosting service is suspended saves the staff choice and leaves the domain paused.
$suspended='The hosting service is suspended. Monitoring resumes when it is active again.';
$services=DB::table((new \App\Models\Service)->getTable())->where('id',$service->id);$services->update(['status'=>'suspended']);
// Staff paused it too, so the page does not promise an automatic resume.
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSee('Paused')->assertDontSee($suspended)
    ->assertSee('The hosting service is suspended. Resume monitoring to start checks when it is active again.')->assertActionVisible('resume');
$resumes=\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_resume')->count();
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertActionVisible('resume')->callAction('resume')
    ->assertNotified(\Filament\Notifications\Notification::make()->success()->title('Monitoring will resume')->body($suspended));
same('paused',WatchName::find($id)->state,'A suspended hosting service keeps the domain paused');
same($resumes+1,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_resume')->count(),'Resume is recorded');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSee('Paused')->assertSee($suspended)->assertActionHidden('resume')->assertActionHidden('read');
dnsRefused(fn()=>(new Writes())->run($manager,'watch',$id,'resume',Writes::ticket($manager->id,'watch',$id,WatchName::find($id)->revision,'resume'),'Monitoring resumed'),'A repeated resume changes nothing',false);
same(['value'=>'Off','sub'=>'Paused. Alerts resume with monitoring.'],$alerts(),'Alerts stay off while the hosting service is suspended');
// History says the resume is saved, not that monitoring resumed.
same(['Monitoring will resume',null],[WatchFacts::history(WatchName::find($id))[0]['event'],WatchFacts::history(WatchName::find($id))[0]['note']],'A resume saved during suspension is not recorded as resumed');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSeeInOrder(['History','Monitoring will resume','Monitoring paused']);
$failed=clone WatchName::find($id);$failed->setRelation('work',$failed->work->map(fn($job)=>clone $job));$failed->work->firstWhere('kind','expiry')->safe_code='read_unavailable';
same('Registry lookup failed. Checks start again when the hosting service is active.',WatchFacts::lastCheck($failed,(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->staffWatches([$id])['watch:'.$id])['sub'],'Failed results do not ask for a Resume that is hidden');
// When the service is active again, discovery applies the saved resume.
$services->update(['status'=>'active']);
DB::transaction(fn()=>(new ReflectionMethod(\Paymenter\Extensions\Servers\Domains\Services\WatchDiscovery::class,'sync'))->invoke(app(\Paymenter\Extensions\Servers\Domains\Services\WatchDiscovery::class),$id,time()));
same('active',WatchName::find($id)->state,'Monitoring resumes with the hosting service');
same(['value'=>'On','sub'=>null],$alerts(),'Alerts come back with monitoring');same($saved,$prefs(),'The customer alert choice is unchanged');
// A name registered with us has no monitoring alerts here, even when the customer saved them On.
$linked=DB::transaction(fn()=>$makeLinked('linked-alerts.example'));$scope=\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::scope($uid,'linked-alerts.example');
DB::table('domains_watch_preferences')->insertOrIgnore(['scope'=>$scope,'user_id'=>$uid,'enabled'=>true]);$linkedSaved=\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::read($uid,'linked-alerts.example');
same(true,\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::enabled($uid,'linked-alerts.example'),'The customer saved linked alerts On');
same(['value'=>'Off','sub'=>'Monitoring stopped'],array_intersect_key(WatchFacts::for($linked)['Checks']['Monitoring alerts'],['value'=>1,'sub'=>1]),'A linked name shows alerts Off');
same($linkedSaved,\Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::read($uid,'linked-alerts.example'),'Showing a linked name keeps the saved choice');
$nativeSuccessful=true;echo "Staff monitoring actions passed\n";
