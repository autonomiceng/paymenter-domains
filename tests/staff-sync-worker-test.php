<?php
// Queue serialization, real observer and the native page cache require one isolated application.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
require_once __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Artisan,Auth,Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema};
ObservationSchema::install();[$d,$driver]=$holding(placeholderComFixtureName('staff-sync-placeholder.com'));
staffPanel();$actor=staffUser([Staff::VIEW,'admin.domains.manage']);Auth::setUser($actor);
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports);
app()->instance(DomainObservation::class,$observer);
Queue::swap(Queue::getFacadeRoot()->queue);
config(['queue.default'=>'database','queue.connections.database.retry_after'=>360,'cache.default'=>'database']);
Illuminate\Support\Facades\Schema::create('cache',function($t){$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');});
Illuminate\Support\Facades\Schema::create('cache_locks',function($t){$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
Cache::flush();
$page=Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id]);
$page->assertSee('Not read yet');
$clock->instant=$clock->now()->modify('+61 seconds');
$page->callAction('sync_now')->assertNotified('Sync queued')->assertSee('Sync requested')->assertSee('Sync in progress')->assertActionDisabled('sync_now');
same(1,DB::table('jobs')->count(),'One queued job');
$queuedHtml=$page->html();
Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
same(0,DB::table('jobs')->count(),'Worker consumes the job');
same(0,DB::table('failed_jobs')->count(),'No failed queue job');
same('Domain synced',App\Models\Notification::where('user_id',$actor->id)->latest('id')->value('title'),'Worker completes a registrar read');
same($clock->now()->format(DATE_ATOM),$d->fresh()->last_synced_at->format(DATE_ATOM),'Worker advances saved domain facts');
$page->call('pollManagement')->assertSee('0 records')->assertDontSee('Not read yet')->assertSee('Domain synced');
expect(str_contains($queuedHtml,'wire:poll.2s.keep-alive="pollManagement"'),'An open staff page must poll while its sync is queued');
expect(!str_contains($page->html(),'wire:poll.'),'Completed sync stops polling');
$syncEvents=array_values(array_filter(\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::entries($d->fresh()),fn($entry)=>is_array($entry)&&in_array($entry['event']??null,['Sync requested','Domain synced'],true)));
same('Domain synced',$syncEvents[0]['event']??null,'Same-second completion is newer than its request in Activity');
// A pre-read failure used to disappear into the notification inbox, leaving old facts with no page explanation.
$last=$d->fresh()->last_synced_at->format(DATE_ATOM);
DB::table('extensions')->where('id',$d->server_id)->update(['enabled'=>false]);
same(true,SyncDomainRecords::enqueue($actor->id,$d->fresh()),'A second explicit sync can queue after completion');
Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
same("Couldn't sync domain",App\Models\Notification::where('user_id',$actor->id)->latest('id')->value('title'),'The job runs and records its failed read');
same($last,$d->fresh()->last_synced_at->format(DATE_ATOM),'A failed read preserves the last successful sync time');
$page->call('pollManagement')->assertSee("Couldn't sync domain")->assertSee('Check the registrar account, then try Sync now again.');
expect(!str_contains($page->html(),'wire:poll.'),'Failed sync stops polling');
// Manual Sync now parks only idle scheduled reads when the domain is outside this account.
DB::table('extensions')->where('id',$d->server_id)->update(['enabled'=>true]);
// The completed registration already queued this holding's scheduled read.
$work=DB::table('domains_observation_work')->where(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,
    'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::phase($d->fresh())])->sole()->id;
DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>'refreshed','due_at'=>$clock->now()->getTimestamp()+86400]);
$observation->fault='owner';$clock->instant=$clock->now()->modify('+61 seconds');
$page->callAction('sync_now')->assertNotified('Sync queued');
Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
$page->call('pollManagement')->assertSee('Domain is not in the registrar account.');
same('domain_not_in_account',DB::table('domains_observation_work')->where('id',$work)->value('safe_code'),'Manual missing-account read stops idle scheduled work');
// Manual and scheduled reads share one lease, so they never read the same holding at once.
$observation->fault='';
$scheduled=['safe_code'=>'refreshed','claim'=>str_repeat('a',32),'lease_until'=>$clock->now()->getTimestamp()+600];
DB::table('domains_observation_work')->where('id',$work)->update($scheduled);
$clock->instant=$clock->now()->modify('+61 seconds');$calls=count($observation->calls);
$page->call('pollManagement')->assertSee('Sync in progress')->assertActionDisabled('sync_now');
$refused=null;try{SyncDomainRecords::enqueue($actor->id,$d->fresh());}catch(DomainException $error){$refused=$error->getMessage();}
same(SyncDomainRecords::RUNNING,$refused,'Admission refuses while a scheduled read holds the lease');
same(0,DB::table('jobs')->count(),'A refused admission queues nothing');
// The scheduler can claim after admission; the worker then yields without a registrar read.
DB::table('domains_observation_work')->where('id',$work)->update(['claim'=>null,'lease_until'=>0]);
same(true,SyncDomainRecords::enqueue($actor->id,$d->fresh()),'Admission accepts once the lease is released');
same(false,SyncDomainRecords::enqueue($actor->id,$d->fresh()),'A simultaneous second admission joins the queued sync');
DB::table('domains_observation_work')->where('id',$work)->update($scheduled);
Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
same('Sync not started',SyncDomainRecords::result($d->fresh())['title'],'The worker yields to a scheduled claim taken after admission');
same($calls,count($observation->calls),'The yielding worker makes no registrar call');
$row=DB::table('domains_observation_work')->where('id',$work)->first();
same([$scheduled['claim'],$scheduled['lease_until'],'refreshed'],[$row->claim,(int)$row->lease_until,$row->safe_code],'Manual sync never steals or rewrites a scheduled claim');
// A manual read that loses its lease to the scheduler mid-read saves nothing and leaves the new claim alone.
$clock->instant=$clock->now()->modify('+601 seconds');$last=$d->fresh()->last_synced_at->format(DATE_ATOM);
DB::table('domains_observation_work')->where('id',$work)->update(['claim'=>null,'lease_until'=>0]);
same(true,SyncDomainRecords::enqueue($actor->id,$d->fresh()),'A new manual sync queues after the scheduled read');
$taken=null;$observation->during=static function()use($work,$clock,&$taken):void {
    $row=DB::table('domains_observation_work')->where('id',$work)->first();
    same(true,$row->claim!==null&&(int)$row->lease_until>$clock->now()->getTimestamp(),'The running manual read holds the shared lease');
    $taken=['claim'=>str_repeat('b',32),'lease_until'=>$clock->now()->getTimestamp()+300];
    DB::table('domains_observation_work')->where('id',$work)->update($taken);
};
Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
same('Sync stopped',SyncDomainRecords::result($d->fresh())['title'],'A manual read that lost its lease reports a stopped sync');
same($last,$d->fresh()->last_synced_at->format(DATE_ATOM),'A manual read without its lease saves no facts');
$row=DB::table('domains_observation_work')->where('id',$work)->first();
same([$taken['claim'],$taken['lease_until']],[$row->claim,(int)$row->lease_until],'The scheduler keeps the claim it took');
DB::table('domains_observation_work')->where('id',$work)->update(['claim'=>null,'lease_until'=>0]);
// A completed manual read releases its own lease and does not wait for the scheduler.
$clock->instant=$clock->now()->modify('+61 seconds');
same(true,SyncDomainRecords::enqueue($actor->id,$d->fresh()),'Manual sync queues on idle work');
Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
same('Domain synced',SyncDomainRecords::result($d->fresh())['title'],'Manual sync completes under its own lease');
$row=DB::table('domains_observation_work')->where('id',$work)->first();
same([null,0,'refreshed'],[$row->claim,(int)$row->lease_until,$row->safe_code],'Manual completion releases its lease and records the read');
same(0,DB::table('failed_jobs')->count(),'Missing-account reads finish without failed queue jobs');
// An ownership read can finish with drift. That is an outcome, not a successful record sync.
$observation->fault='';$observation->expiryYears=2;$clock->instant=$clock->now()->modify('+61 seconds');
$page->callAction('sync_now')->assertNotified('Sync queued');
Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
$page->call('pollManagement')->assertSee('Sync needs a check')->assertDontSee('Sync in progress')->assertActionEnabled('sync_now');
same('Sync needs a check',SyncDomainRecords::result($d->fresh())['title'],'Unresolved registrar facts cannot claim Domain synced');
expect(!str_contains($page->html(),'wire:poll.'),'Unresolved outcome stops pending progress');
// Unreadable saved observation must not block queueing or hide the sync result.
$raw=DB::table('domains_domains')->where('id',$d->id)->value('observation');
DB::table('domains_domains')->where('id',$d->id)->update(['observation'=>'unreadable']);
$clock->instant=$clock->now()->modify('+61 seconds');
same(true,SyncDomainRecords::enqueue($actor->id,$d->fresh()),'Sync queues even when history cannot be saved');
Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
same("Couldn't sync domain",SyncDomainRecords::result($d->fresh())['title']??null,'The sync result is cached when history cannot be saved');
same("Couldn't sync domain",App\Models\Notification::where('user_id',$actor->id)->latest('id')->value('title'),'Staff still get the sync notification');
same(0,DB::table('failed_jobs')->count(),'Unreadable history fails no queue job');
DB::table('domains_domains')->where('id',$d->id)->update(['observation'=>$raw]);
$nativeSuccessful=true;echo "Native database worker refreshes saved facts and the open staff page.\n";
