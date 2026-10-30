<?php
// Native installed staff page with fake downloads only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php'; require __DIR__.'/Support/staff-panel.php'; require __DIR__.'/Support/psl.php';
use Illuminate\Support\Facades\{Auth,DB,Route,Schema};
use Filament\Facades\Filament;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList as Page;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,PslFacts};
use Paymenter\Extensions\Servers\Domains\Services\{PublicSuffixList as Lists,PslTransport,PslValidator,RuntimeWork,Installation};
$entry=new Paymenter\Extensions\Servers\Domains\Domains([]);$entry->installed();$entry->boot();
staffPanel();$panel=Filament::getCurrentPanel();$panel->pages([Page::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())->group(fn()=>Route::middleware($panel->getAuthMiddleware())->group(fn()=>Page::registerRoutes($panel)));
app('router')->getRoutes()->refreshNameLookups();app('router')->getRoutes()->refreshActionLookups();
$clock=new PslClock;$count=0;$duringFetch=null;$list=new Lists($clock,new PslTransport(function()use(&$count,&$duringFetch){$count++;if($duringFetch){$run=$duringFetch;$duringFetch=null;$run();}return ['status'=>200,'body'=>pslFixture()];}),new PslValidator(128,5,1));
app()->instance(Lists::class,$list);app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$job=new \Paymenter\Extensions\Servers\Domains\Jobs\UpdatePublicSuffixList;
$events=app(Illuminate\Console\Scheduling\Schedule::class)->events();
$scheduled=array_values(array_filter($events,fn($e)=>$e->description==='domains-public-suffix-list'));
same(1,count($scheduled),'Exactly one separate PSL schedule');expect($scheduled[0]->withoutOverlapping&&$scheduled[0]->onOneServer,'PSL schedule owns an overlap lock');
$runMinute=function(int $at)use($clock,$scheduled):void {
    $clock->at=$at;Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestampUTC($at));
    if($scheduled[0]->isDue(app())){$scheduled[0]->run(app());}
};
$bus=Illuminate\Support\Facades\Bus::getFacadeRoot();$savedStatus=(array)$list->status();$savedAt=$clock->at;
$base=(new DateTimeImmutable('2026-10-23 12:00:00 UTC'))->getTimestamp();
same(null,$list->current(),'Fresh install has no saved list');same(null,$list->status()->last_attempt_at,'Fresh install has never attempted a download');
Illuminate\Support\Facades\Bus::fake([get_class($job)]);$runMinute($base+60);
Illuminate\Support\Facades\Bus::assertDispatchedTimes(get_class($job),1);
Illuminate\Support\Facades\Bus::assertDispatched(get_class($job),fn($sent)=>$sent->queue===null);
same($savedStatus,(array)$list->status(),'Fresh-install eligibility does not write status');same(0,$count,'Scheduler only queues the fresh-install job');
$runMinute($base+60);Illuminate\Support\Facades\Bus::assertDispatchedTimes(get_class($job),1);
app(Illuminate\Bus\UniqueLock::class)->release($job);
// Release the queue lock on every turn so it cannot hide redundant dispatches.
DB::table('domains_psl_status')->where('id',1)->update(['last_attempt_at'=>$base,'last_network_fetch_at'=>$base,'last_result'=>'timeout']);
$recentStatus=(array)$list->status();Illuminate\Support\Facades\Bus::fake([get_class($job)]);
for($minute=1;$minute<=1440;$minute++){$runMinute($base+$minute*60);app(Illuminate\Bus\UniqueLock::class)->release($job);}
Illuminate\Support\Facades\Bus::assertNotDispatched(get_class($job));
same($recentStatus,(array)$list->status(),'All 1440 recent-attempt checks leave status unchanged');same(0,$count,'Quiet scheduler never downloads');
DB::table('domains_psl_status')->where('id',1)->update(['last_attempt_at'=>$base-7*86400,'last_network_fetch_at'=>$base-7*86400]);
$dueStatus=(array)$list->status();$runMinute($base-1);Illuminate\Support\Facades\Bus::assertNotDispatched(get_class($job));
$runMinute($base);Illuminate\Support\Facades\Bus::assertDispatchedTimes(get_class($job),1);
same($dueStatus,(array)$list->status(),'Weekly eligibility is read-only at the exact boundary');
$scheduledFailures=0;$scheduledList=new Lists($clock,new PslTransport(function()use(&$scheduledFailures){$scheduledFailures++;throw new \Paymenter\Extensions\Servers\Domains\Services\PslFailure('timeout');}),new PslValidator(128,5,1));
$job->handle($scheduledList);same(1,$scheduledFailures,'The dispatched weekly job performs one real attempt');
app(Illuminate\Bus\UniqueLock::class)->release($job);
for($minute=1;$minute<=1440;$minute++){$runMinute($base+$minute*60);app(Illuminate\Bus\UniqueLock::class)->release($job);}
Illuminate\Support\Facades\Bus::assertDispatchedTimes(get_class($job),1);same(1,$scheduledFailures,'A failed weekly job dispatches no further work for 1440 minutes');
// Both never-attempted and weekly-due status must respect the shared network window.
foreach([null,$base-7*86400]as$attempt){
    DB::table('domains_psl_status')->where('id',1)->update(['last_attempt_at'=>$attempt,'last_network_fetch_at'=>$base-86400]);
    $networkStatus=(array)$list->status();Illuminate\Support\Facades\Bus::fake([get_class($job)]);
    $runMinute($base-1);Illuminate\Support\Facades\Bus::assertNotDispatched(get_class($job));
    same($networkStatus,(array)$list->status(),'Network window skips without writing status');
    $runMinute($base);Illuminate\Support\Facades\Bus::assertDispatchedTimes(get_class($job),1);
    same($networkStatus,(array)$list->status(),'Exact 24-hour boundary queues without claiming an attempt');
    app(Illuminate\Bus\UniqueLock::class)->release($job);
}
Schema::rename('domains_psl_status','domains_psl_status_saved');Illuminate\Support\Facades\Bus::fake([get_class($job)]);
$runMinute($base+60);Illuminate\Support\Facades\Bus::assertNotDispatched(get_class($job));
Schema::rename('domains_psl_status_saved','domains_psl_status');
DB::table('domains_psl_status')->where('id',1)->update($savedStatus);$clock->at=$savedAt;
Illuminate\Support\Carbon::setTestNow();Illuminate\Support\Facades\Bus::swap($bus);
$rights=[Staff::VIEW,Page::VIEW,Page::MANAGE];$staff=staffUser($rights);Auth::setUser($staff);
$setRights=function($permissions)use($staff){$staff->role()->first()->update(['permissions'=>$permissions]);};
$setRights([]);same(false,Page::canAccess(),'Default deny');Livewire::actingAs($staff)->test(Page::class)->assertForbidden();
$setRights([Staff::VIEW,Page::MANAGE]);same(false,Page::canAccess(),'Manage cannot view alone');
$setRights([Staff::VIEW,Page::VIEW]);Livewire::actingAs($staff)->test(Page::class)->assertSee('Not downloaded yet')->assertActionHidden('psl_download')->assertActionHidden('psl_source');same(0,$count,'GET no fetch');
$setRights($rights);
$page=Livewire::actingAs($staff)->test(Page::class)->assertSee('MPL-2.0')->assertSee('More help')->assertActionEnabled('psl_download');
expect(!$page->instance()->getCachedHeaderActions()[0]->isConfirmationRequired(),'Download now has no dialog');same(0,$count,'Showing Download now does not fetch');
$update=$page->instance()->getCachedHeaderActions()[0]->getActionFunction();
$setRights([Staff::VIEW,Page::VIEW]);$page->assertActionHidden('psl_download');same(0,$count,'Fresh manage revoked');
$denied=false;try{$update();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$denied=$e->getStatusCode()===403;}expect($denied,'Cached update callback rechecks manage');
$setRights($rights);$page=Livewire::actingAs($staff)->test(Page::class)->mountAction('psl_source')->setActionData(['url'=>'https://mirror.example.com/list']);
$source=$page->instance()->getCachedHeaderActions()[2]->getActionFunction();
$setRights([Staff::VIEW,Page::VIEW]);same(Lists::SOURCE,$list->source(),'Revoked source stays saved');
$denied=false;try{$source(['url'=>'https://mirror.example.com/list']);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$denied=$e->getStatusCode()===403;}expect($denied,'Cached source callback rechecks manage');
$setRights($rights);
Livewire::actingAs($staff)->test(Page::class)->mountAction('psl_source')->setActionData(['url'=>'http://mirror.example.com/list'])->callMountedAction()->assertHasActionErrors(['url']);
Livewire::actingAs($staff)->test(Page::class)->mountAction('psl_source')->setActionData(['url'=>'https://mirror.example.com/list'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Source saved');same(0,$count,'Saving source no network');
Livewire::actingAs($staff)->test(Page::class)->callAction('psl_download')->assertNotified('List updated')->assertActionHidden('psl_download')->assertSee('Next update allowed:');
same(1,$count,'Native manual fetched once');same('Current',PslFacts::state($list),'Current state');
// The page's expiry date must match the day new discovery actually stops.
Livewire::actingAs($staff)->test(Page::class)->assertSee('Out of date after '.PslFacts::time((int)$list->current()->validated_at+90*86400).'.');
$before=DB::table('domains_psl_snapshots')->where('state','current')->first();
$source(['url'=>'https://after-complete.example.com/list']);
same('updated',$list->status()->last_result,'Source change preserves a completed update result');
$source(['url'=>'https://mirror.example.com/list']);
$clock->at+=86400;$duringFetch=fn()=>$source(['url'=>'https://replacement.example.com/list']);
same('superseded',$list->update(),'Changing source invalidates the old in-flight fetch');
same((array)$before,(array)$list->current(),'Old-source completion leaves the current snapshot unchanged');
same(null,$list->status()->attempt_token,'Source change clears the attempt token');
same('superseded',$list->status()->last_result,'Source change replaces the in-progress status');
expect(str_contains(PslFacts::result($list->status()->last_result),'source changed'),'Source change has a staff explanation');
same('rate_limited',$list->update(),'Source change preserves the daily fetch limit');
$clock->at+=86400;$duringFetch=fn()=>$source(['url'=>'https://replacement.example.com/list']);
same('identical',$list->update(),'Saving the same source preserves an in-flight fetch');
$clock->at+=7*86400-1;app(RuntimeWork::class)->tick();same(3,$count,'Runtime work does not fetch the weekly list');
$job->handle($list);same(3,$count,'Separate job keeps weekly admission');
$clock->at++;app(RuntimeWork::class)->tick();same(3,$count,'Due PSL fetch stays outside the shared runtime');$job->handle($list);same(4,$count,'Separate job runs the weekly update without accounts');
same(120,$job->timeout,'Public-data job has a separate execution timeout');
same('domains-public-suffix-list',$job->uniqueId(),'Separate job has its own unique lock');
$clock->at+=60;$job->handle($list);same(4,$count,'Fresh cached list skips the download through the job');
$clock->at+=7*86400-60;$failures=0;
$failedList=new Lists($clock,new PslTransport(function()use(&$failures){$failures++;throw new \Paymenter\Extensions\Servers\Domains\Services\PslFailure('timeout');}),new PslValidator(128,5,1));
$saved=$list->current();$job->handle($failedList);
same(1,$failures,'Due job attempts one failed download');same('timeout',$list->status()->last_result,'Job retains the download failure');
$failedAt=$list->status()->last_attempt_at;
for($minute=1;$minute<=5;$minute++){$clock->at+=60;$job->handle($failedList);}
same(1,$failures,'Failed download does not retry each minute through the job');
same($failedAt,$list->status()->last_attempt_at,'Skipped jobs do not renew the failed attempt age');
same((array)$saved,(array)$list->current(),'Failed download retains the cached list');
same(1,$job->tries,'Failed public-data jobs have no queue retry');
$clock->at=$failedAt+7*86400-1;$job->handle($failedList);same(1,$failures,'Failed download preserves weekly admission');
$clock->at++;$job->handle($failedList);same(2,$failures,'Failed download becomes eligible again after a week');

$clock->at=(int)$list->current()->validated_at+90*86400;Livewire::actingAs($staff)->test(Page::class)->assertSee('The list is current.');
$clock->at++;Livewire::actingAs($staff)->test(Page::class)->assertSee('Out of date')->assertSee('The list is over 90 days old.');
DB::table('domains_psl_status')->where('id',1)->update(['last_result'=>'rules_lost_private']);Livewire::actingAs($staff)->test(Page::class)->assertSee('Many rules were removed. Review them before using this list.');
DB::table('domains_psl_status')->where('id',1)->update(['last_result'=>'dns_failed']);
same("Couldn't download: the source name didn't resolve.",PslFacts::rows($list)['Last attempt result'],'Page facts keep a DNS failure apart from an unsafe address');
Livewire::actingAs($staff)->test(Page::class)->assertSee("Couldn't download: the source name didn't resolve.");
$manualFailures=0;$manualList=new Lists($clock,new PslTransport(function()use(&$manualFailures){$manualFailures++;throw new \Paymenter\Extensions\Servers\Domains\Services\PslFailure('dns_failed');}),new PslValidator(128,5,1));
app()->instance(Lists::class,$manualList);$manualSnapshot=(array)$manualList->current();$clock->at+=86400;
session()->forget('filament.notifications');
Livewire::actingAs($staff)->test(Page::class)->callAction('psl_download')->assertHasNoActionErrors()
    ->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't download: the source name didn't resolve."));
same(1,$manualFailures,'Allowed manual download attempts exactly one fake exchange');
same('dns_failed',$manualList->status()->last_result,'Manual action retains the safe failure');
same($manualSnapshot,(array)$manualList->current(),'Manual failed download keeps current bytes');

$page=Livewire::actingAs($staff)->test(Page::class);$setRights([Staff::VIEW,Page::MANAGE]);$page->call('$refresh')->assertForbidden();
// The native migrator/retained-history preflight must neither replace lost data nor repair partial installs.
$setRights($rights);$entry->upgraded('0.1.0');$entry->uninstalled();same(1,DB::table('domains_psl_snapshots')->count(),'Lifecycle retains snapshot');
DB::table('migrations')->where('migration','2026_10_19_000007_create_psl_snapshots')->delete();
try{$entry->installed();expect(false,'Missing PSL migration history refused');}catch(DomainException $e){expect(str_contains($e->getMessage(),'public suffix history'),'Clear retention refusal');}
echo "Installed public suffix staff view/manage denial, fresh permissions, settings, cap, runtime cadence and retention passed.\n";$nativeSuccessful=true;
