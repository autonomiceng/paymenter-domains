<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/demo-c-c1-observation.php';
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{DomainObservation,HoldingObservationWork,HoldingReadRetry,ObservationRecords};

staffPanel();$actor=staffUser([Staff::VIEW,'admin.domains.manage']);Auth::setUser($actor);
$wired=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$exchange));
$worker=new HoldingObservationWork($wired,$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
$work=static fn()=>HoldingObservationWork::current($d->fresh())->sole();
$due=static function()use($clock,$work):void{$clock->instant=$clock->now()->setTimestamp((int)$work()->due_at);};
$money=$nativeMoney();$originalBefore=$original->fresh()->getAttributes();

// A failed settings read saves its successful siblings and retries through the ordinary scheduler.
$state->fault='GetRegLock';$tick();
same('read_failed',$worker->turn($queue,$guard),'A partial settings read schedules a retry');
same([1,60],[(int)$work()->tries,$work()->due_at-$clock->now()->getTimestamp()],'First partial failure uses the existing backoff');
expect(ObservationRecords::held($d->fresh()),'Partial settings remain unconfirmed');
same('complete',$d->fresh()->observation['records']['families']['dns']['state'],'Successful DNS is saved');
same(null,DomainFacts::notice($d->fresh()),'Automatic retry needs no staff warning');
expect(!HoldingObservationWork::stopped($d->fresh()),'Retryable settings do not stop the job');
$page=Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id]);
$page->assertSee('Sync will retry automatically')->assertActionEnabled('sync_now');
expect(str_contains($page->instance()->getSubheading()->toHtml(),'Sync will retry automatically'),'Retry progress sits with the status and Sync now button');
$page->assertActionHidden('resume_observation');
expect(str_contains($page->html(),'wire:poll.2s.keep-alive="pollManagement"'),'The page follows the automatic retry');
$state->calls=[];same('idle',$worker->turn($queue,$guard),'The backoff prevents immediate repeat reads');same([],$state->calls,'No reads before due');
$state->fault='';$due();same('refreshed',$worker->turn($queue,$guard),'The next scheduled read heals the settings');
expect(!ObservationRecords::held($d->fresh()),'Complete read clears the warning evidence');
same([0,false],[(int)$work()->tries,HoldingObservationWork::stopped($d->fresh())],'Success resets retries');
$page->call('pollManagement')->assertDontSee('Sync will retry automatically')->assertDontSee('Sync stopped');

// Manual Sync now must schedule the same recovery instead of leaving the old daily due time.
$state->fault='GetWPPSInfo';$tick();
$manual=new SyncDomainRecords((int)$actor->id,(int)$d->id,$d->authority_generation,$d->account_fingerprint);
$manual->handle($wired);
same([1,60],[(int)$work()->tries,$work()->due_at-$clock->now()->getTimestamp()],'Manual partial read schedules automatic recovery');
same('Sync retry scheduled',SyncDomainRecords::result($d->fresh())['title'],'Manual partial read reports automatic recovery');
expect(in_array('Sync retry scheduled',array_column(\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::entries($d->fresh()),'event'),true),'History records that manual sync scheduled automatic recovery');
$state->fault='';$due();same('refreshed',$worker->turn($queue,$guard),'Scheduled worker heals manual partial read');
same(null,SyncDomainRecords::result($d->fresh()),'Automatic success clears the cached partial result');

// A partial job saved by the previous policy resumes without staff resetting its budget.
$state->fault='GetRegLock';$tick();$wired->run($d->id,readRecords:true);
HoldingObservationWork::current($d)->update(['safe_code'=>'read_exception','tries'=>1,'due_at'=>$clock->now()->getTimestamp()]);
same('read_failed',$worker->turn($queue,$guard),'An old partial stop resumes through the existing worker');
same(2,(int)$work()->tries,'Resuming old partial work preserves its attempts');
$state->fault='';$due();$worker->turn($queue,$guard);

// Unknown flag values are partial without a transport exception, and need the same bounded retries.
$unknownFlags=static function(...$args)use($exchange):string{
    $body=$exchange(...$args);
    return $args[1]==='GetRegLock'?str_replace('<Reg-Lock>1</Reg-Lock>','<Reg-Lock></Reg-Lock>',$body):$body;
};
$unknown=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$unknownFlags));
$unknownWorker=new HoldingObservationWork($unknown,$clock);
HoldingObservationWork::current($d)->update(['due_at'=>$clock->now()->getTimestamp()+61]);$due();
for($try=1;$try<=HoldingReadRetry::LIMIT;$try++){
    same('read_failed',$unknownWorker->turn($queue,$guard),'Partial flag retry '.$try);
    same($try,(int)$work()->tries,'Each failed attempt consumes its retry budget');
    same(HoldingReadRetry::delay($try),$work()->due_at-$clock->now()->getTimestamp(),'Bounded backoff '.$try);
    same($try===HoldingReadRetry::LIMIT,HoldingObservationWork::stopped($d->fresh()),'Warning appears only after the retry budget');
    if($try<HoldingReadRetry::LIMIT){same(null,DomainFacts::notice($d->fresh()),'No warning before retries finish');}
    $due();
}
$state->calls=[];same('idle',$unknownWorker->turn($queue,$guard),'Exhausted work stays stopped');same([],$state->calls,'Exhaustion sends no more reads');
$notice=DomainFacts::notice($d->fresh());
same("Couldn't read lock, auto-renew and privacy settings after 5 attempts; use Sync now to try again.",$notice['title'],'Exhausted warning names the settings and one action');
same(null,$notice['body'],'No duplicate recovery instruction');
$page->call('pollManagement')->assertSee('after 5 attempts')->assertActionEnabled('sync_now');
$manual->handle($wired);
expect(!ObservationRecords::held($d->fresh()),'Manual override succeeds after exhaustion');
same(0,(int)$work()->tries,'Manual success resets the exhausted budget');

// A permanent unsupported family must still warn immediately instead of burning retries.
$state->dns[]=$caa;$tick();HoldingObservationWork::current($d)->update(['due_at'=>$clock->now()->getTimestamp()]);
$worker->turn($queue,$guard);
expect(HoldingObservationWork::stopped($d->fresh()),'Unsupported CAA stops immediately');
expect(str_contains(DomainFacts::notice($d->fresh())['title'],"CAA records"),'Permanent cause stays explicit');
same($money,$nativeMoney(),'Read retries make no financial changes');
same($originalBefore,$original->fresh()->getAttributes(),'Read retries preserve the original registration');
foreach($state->calls as [$command]){expect(str_starts_with($command,'Get')||$command==='CheckNSStatus','Retries send only reads');}
$nativeSuccessful=true;echo "Partial sync scheduling, manual recovery, bounded exhaustion and permanent failure passed.\n";
