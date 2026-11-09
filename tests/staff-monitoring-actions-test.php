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
$nativeSuccessful=true;echo "Staff monitoring actions passed\n";
