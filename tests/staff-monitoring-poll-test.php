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
// A queued check keeps feedback on the current page rather than losing the toast to a redirect.
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900]);
// Distinct DOM identity disconnects the prior Livewire timer when switching cadence.
$pollKey=static function($page):string{
    $doc=new DOMDocument;@$doc->loadHTML($page->html());
    $nodes=(new DOMXPath($doc))->query('//*[@*[starts-with(name(),"wire:poll")]]');
    same(1,$nodes->length,'Exactly one polling element');$key=$nodes->item(0)->getAttribute('wire:key');
    expect($key!=='','Polling element has a stable key');return $key;
};
$secondTab=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false);
$passiveKey=$pollKey($secondTab);
$check=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->callAction('read')->assertSee('wire:poll.5s="pollCheck"',false)
    ->assertNotified(\Filament\Notifications\Notification::make()->success()->title('Check requested')
        ->body('This takes up to about six minutes. This page updates by itself.')->persistent())
    ->assertNoRedirect();
$pendingKey=$pollKey($check);expect($pendingKey!==$passiveKey,'Changing polling mode replaces the timer element');
same('Monitoring uses 0 registrar reads per domain per day.',$check->instance()->getAction('read')->getTooltip(),'Check cost is visible before requesting it');
same(1,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_read_requested')->count(),'Check request recorded once');
$originalWait=$check->get('pendingCheck');
$requestAt=\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_read_requested')->first()->created_at->getTimestamp();
same($requestAt+360,$originalWait[0],'Deadline starts at the stored accepted request time');
\Carbon\Carbon::setTestNow(now()->addSeconds(120));
$waitingPages=['second tab'=>$secondTab,'reload'=>Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])];
foreach($waitingPages as $label=>$waitingPage){
    if($label==='reload'){$waitingPage->assertSee('wire:poll.5s="pollCheck"',false);same($originalWait,$waitingPage->get('pendingCheck'),'Reload restores the original wait');}
    $waitingPage->callAction('read')->assertSee('wire:poll.5s="pollCheck"',false)->assertNotified('Check already requested')->assertNoRedirect();
    same($originalWait,$waitingPage->get('pendingCheck'),$label.' joins the same wait without extending it');
}
$check->callAction('read')->assertNotified('Check already requested');
same($originalWait,$check->get('pendingCheck'),'Retry preserves the original deadline');
same(1,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_read_requested')->count(),'Throttled tabs do not add a request');
\Carbon\Carbon::setTestNow();
$check->call('pollCheck')->assertSee('wire:poll.5s="pollCheck"',false)->assertNotNotified('Check finished');
same(WatchFacts::date($at+900),panelRowValue($check->html(),'Last check'),'Pending page keeps its saved Last check');
// A read already in flight can start before the request and save a newer Last check afterwards.
$completedAt=$requestAt-1;DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','delegation')->update(['due_at'=>$completedAt,'backoff_until'=>null]);
$t=$dns->begin($id,$uid,'delegation',$completedAt);expect($t!==null,'In-flight DNS ticket');
expect($dns->finish($t,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external'),$requestAt+1),'In-flight DNS result saved after the request');
$check->call('pollCheck')->assertSee(WatchFacts::date($completedAt))->assertNotified('Check finished')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNoRedirect();
same($passiveKey,$pollKey($check),'Completion restores the stable passive element');
same(WatchFacts::date($completedAt),panelRowValue($check->html(),'Last check'),'Poll updates the Last check entry itself');
$check->call('pollCheck')->assertNotNotified('Check finished');
foreach($waitingPages as $label=>$waitingPage){
    $waitingPage->call('pollCheck')->assertNotified('Check finished')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false);
    same(WatchFacts::date($completedAt),panelRowValue($waitingPage->html(),'Last check'),$label.' receives the new Last check');
}
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotNotified('Check finished');
// Autonomous work after Check finished must refresh this same open page, including Next check.
$autonomousAt=$requestAt+120;
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points')->update(['due_at'=>$autonomousAt,'backoff_until'=>null]);
$t=$dns->begin($id,$uid,'points',$autonomousAt);expect($t!==null,'Autonomous points ticket');
$check->call('refreshFacts')->assertNotNotified('Check finished');same(\Paymenter\Extensions\Servers\Domains\Presentation\State::Checking->label(),panelRowValue($check->html(),'Next check'),'Passive refresh shows the running check');
expect($dns->finish($t,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('points',website:true,email:true),$autonomousAt+1),'Autonomous points result');
$audits=\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count();
$check->call('refreshFacts')->assertSee(WatchFacts::date($autonomousAt))->assertNotNotified('Check finished');expect(panelRowValue($check->html(),'Next check')!==\Paymenter\Extensions\Servers\Domains\Presentation\State::Checking->label(),'The running check ends on the open page');
same(WatchFacts::date($autonomousAt),panelRowValue($check->html(),'Last check'),'Passive refresh updates Last check on the open page');
same(WatchFacts::for(WatchName::find($id))['Checks']['Next check']['value'],panelRowValue($check->html(),'Next check'),'Passive refresh replaces Next check after work finishes');
same($audits,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count(),'Passive refresh requests no checks or writes');
// Age the synthetic request claim so a second real Check now action can be tested.
DB::table('domains_watch_staff_claims')->where('subject_type','watch')->where('subject_id',$id)->where('action','read')->update(['created_at'=>time()-301]);
// If no saved check moves, support advice appears only after the six-minute wait.
$waitingAt=\Carbon\Carbon::createFromTimestampUTC($autonomousAt+2);\Carbon\Carbon::setTestNow($waitingAt);
$check=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('read');
same($pendingKey,$pollKey($check),'Another request reuses the pending key, not the passive node');
\Carbon\Carbon::setTestNow($waitingAt->copy()->addSeconds(359));
$check->call('pollCheck')->assertSee('wire:poll.5s="pollCheck"',false)->assertNotNotified('Check is taking longer');
$reload=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSee('wire:poll.5s="pollCheck"',false);
same($check->get('pendingCheck'),$reload->get('pendingCheck'),'Reload at 359 seconds keeps the original deadline');
\Carbon\Carbon::setTestNow($waitingAt->copy()->addSeconds(360));
$check->call('pollCheck')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotified(\Filament\Notifications\Notification::make()->warning()->title('Check is taking longer')
    ->body('Last check has not changed. Checks keep retrying. Open Registrars and check the background worker.')->persistent())->assertNoRedirect();
same($passiveKey,$pollKey($check),'Timeout also disconnects the pending timer');
$check->call('pollCheck')->assertNotNotified('Check is taking longer');
$reload->call('pollCheck')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotified('Check is taking longer');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotified('Check is taking longer');
\Carbon\Carbon::setTestNow();

// Staff have no Stop: Pause is the supported way to halt checks, and the page then reads Paused.
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertActionDoesNotExist('retire')->callAction('pause')->assertNotified('Monitoring paused');same('paused',WatchName::find($id)->state,'Pause saves');
same('Paused',WatchFindings::status(WatchName::find($id),[])['label'],'Paused status');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSee('Paused')->assertActionHidden('read')->assertActionHidden('pause')->assertActionVisible('resume')->assertDontSee('wire:poll.5s',false);


$nativeSuccessful=true;echo "Staff monitoring polling passed\n";
