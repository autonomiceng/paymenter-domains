<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/staff-alerts.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{WatchMaintenance,WatchRegistryChecks,WatchDnsChecks};
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
use Paymenter\Extensions\Servers\Domains\Models\WatchRegistryObservation;
extract(dnsFixture());staffAlertFixture();
$staff=[staffUser(['admin.domains.view','admin.domains.watch.view']),staffUser(['admin.domains.view','admin.domains.watch.view'])];
staffUser(['admin.domains.view']);staffUser(['admin.domains.watch.view']);
$panel=\Filament\Facades\Filament::getPanel('admin');
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
$now=1800000000;$m=new WatchMaintenance();same('waiting',$m->health($now)['reason'],'Active checks await first turn');
$logs=[];\Illuminate\Support\Facades\Log::listen(static function($event)use(&$logs):void{$logs[]=$event->message;});
DB::table('domains_watch_schedule')->insert(['scope'=>'dispatch','due_at'=>$now+300,'progress_at'=>$now,'data'=>json_encode(['started_at'=>$now])]);expect(!$m->health($now+599)['outage'],'Two intervals not elapsed');same('dispatch_overdue',$m->health($now+600)['reason'],'Two missed turns alert');$m->tick($now+600);$alert=DB::table('domains_watch_schedule')->where('scope','dispatch')->value('alert_at');same($now+600,(int)$alert,'Outage marker retained');$m->tick($now+700);same((int)$alert,(int)DB::table('domains_watch_schedule')->where('scope','dispatch')->value('alert_at'),'Alert deduplicated');
same(['Domain monitoring needs staff review.'],$logs,'Alert log names Domain monitoring, once');
staffAlerts('Domain monitoring stopped','Checks have not run since Jan 15, 2027 08:00 UTC.',route('filament.admin.resources.domain-watch.index'),$staff);
// Missing native storage/columns skip silently without queueing a fallback.
$send=static fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffAlert::send('Unavailable inbox','Body','missing.route');
\Illuminate\Support\Facades\Schema::rename('notifications','notifications_unavailable');
try { expect(!$send(),'Absent native table skips'); } finally { \Illuminate\Support\Facades\Schema::rename('notifications_unavailable','notifications'); }
\Illuminate\Support\Facades\Schema::table('notifications',fn($table)=>$table->renameColumn('title','unavailable_title'));
try { expect(!$send(),'Incompatible native columns skip'); } finally { \Illuminate\Support\Facades\Schema::table('notifications',fn($table)=>$table->renameColumn('unavailable_title','title')); }
\Illuminate\Support\Facades\Queue::assertNotPushed(\Illuminate\Notifications\SendQueuedNotifications::class,static fn($job)=>$job->notification instanceof \Filament\Notifications\DatabaseNotification);
$c=new WatchRegistryChecks(true);$c->enroll((int)$watch->id,(int)$owner->id,$now);DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$now+86400]);expect(!$m->health($now+86400)['outage'],'Newly enrolled work is not a day-old failure');
$good=['result'=>'facts','reason'=>'facts','safe_code'=>null,'expiry'=>'2030-01-01','precision'=>'date','source'=>'https://rdap.example.com','status'=>[]];
$t=$c->begin((int)$watch->id,(int)$owner->id,$now);$c->finish($t,$good,str_repeat('a',64),$now);$first=(int)WatchRegistryObservation::latest('id')->value('id');DB::table('domains_watch_work')->where('kind','expiry')->update(['due_at'=>$now+100]);$t=$c->begin((int)$watch->id,(int)$owner->id,$now+100);$c->finish($t,$good,str_repeat('a',64),$now+100);$last=(int)WatchRegistryObservation::latest('id')->value('id');
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$now+86400]);expect(!$m->health($now+86400)['outage'],'Healthy daily jitter never alerts');
DB::table('domains_watch_work')->where('kind','expiry')->update(['attempts'=>1]);DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$now+200]);$m->tick($now+200);expect(!$m->health($now+200)['outage'],'Failure must last a day');
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$now+200+86400]);same('checks_unsuccessful',$m->health($now+200+86400)['reason'],'Continuous unsuccessful checks alert after a day');
DB::table('domains_watch_work')->where('kind','expiry')->update(['attempts'=>0,'safe_code'=>'no_coverage','due_at'=>$now+8*86400]);$m->tick($now+200+86400);expect(!$m->health($now+200+86400)['outage'],'Valid semantic gaps clear failure timer');
// A single broken name does not imply a family outage while other exchanges succeed.
DB::table('domains_watch_work')->where('kind','expiry')->update(['attempts'=>1]);$at=$now+2*86400;DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$at]);$m->tick($at);
DB::table('domains_watch_registry')->where('id',$last)->update(['completed_at'=>$at+3600]);DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$at+86400]);expect(!$m->health($at+86400)['outage'],'Other successful registry exchange prevents global outage');
// The latest successful daily job may legitimately wait twenty-five hours beside a failed name.
$copy=(array)$watch->getRawOriginal();$copy['id']=900;$copy['canonical_name']='healthy-placeholder.com';DB::table('domains_watch_names')->insert($copy);
DB::table('domains_watch_work')->insert(['watch_id'=>900,'kind'=>'expiry','watch_revision'=>1,'due_at'=>$at+3600+90000]);
DB::table('domains_watch_registry')->where('id',$last)->update(['watch_id'=>900]);
$check=$at+3600+86400;DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$check]);expect(!$m->health($check)['outage'],'Healthy daily jitter does not raise recurring family outage');
$gap=(array)DB::table('domains_watch_registry')->where('id',$last)->first();unset($gap['id']);$gap['watch_id']=901;$gap['completed_at']=$at+3900;$gap['reason']='no_coverage';$gap['result']='unknown';$gapId=DB::table('domains_watch_registry')->insertGetId($gap);$copy['id']=901;$copy['canonical_name']='gap-placeholder.com';DB::table('domains_watch_names')->insert($copy);DB::table('domains_watch_work')->insert(['watch_id'=>901,'kind'=>'expiry','watch_revision'=>1,'due_at'=>$at+3900+604800]);
$check=$at+3900+86400;DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$check]);expect(!$m->health($check)['outage'],'Later no-coverage success does not hide another daily job cadence');
$running=$at+3600+90000;DB::table('domains_watch_work')->where('watch_id',900)->update(['claim'=>'running','lease_until'=>$running+120]);DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$running]);expect(!$m->health($running)['outage'],'A live daily read keeps its bounded completion grace');DB::table('domains_watch_work')->where('watch_id',900)->update(['claim'=>null,'lease_until'=>null]);
$check=$at+3600+90600;DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$check]);same('checks_unsuccessful',$m->health($check)['reason'],'Stalled healthy daily job cannot hide outage beyond expected turn');
DB::table('domains_watch_registry')->where('id',$gapId)->delete();DB::table('domains_watch_work')->where('watch_id',901)->delete();DB::table('domains_watch_names')->where('id',901)->delete();DB::table('domains_watch_registry')->where('id',$last)->update(['watch_id'=>$watch->id]);DB::table('domains_watch_work')->where('watch_id',900)->delete();DB::table('domains_watch_names')->where('id',900)->delete();
// Exceptions/crashes that never update attempts still leave due work without progress.
DB::table('domains_watch_work')->where('kind','expiry')->update(['attempts'=>0,'due_at'=>$at]);DB::table('domains_watch_schedule')->where('scope','like','health_%')->delete();$m->tick($at+86400);DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$at+2*86400]);$h=$m->health($at+2*86400);same('registry',$h['family'],'Due work with no completion is detected without attempts');$n=WatchMaintenance::notice($h);same(null,$n['action'],'Registry outage advice does not send staff to the resolver');expect(str_starts_with($n['text'],'Expiry checks have failed since '),'Registry outage names expiry checks');
// A new family creates a new episode even while an older family remains down.
$m->tick($at+2*86400);$oldAlert=(int)DB::table('domains_watch_schedule')->where('scope','dispatch')->value('alert_at');
DB::table('domains_watch_work')->insert(['watch_id'=>$watch->id,'kind'=>'points','watch_revision'=>1,'due_at'=>$at]);DB::table('domains_watch_schedule')->insert(['scope'=>'health_watch_dns','cursor'=>$at]);$m->tick($at+2*86400+1);expect((int)DB::table('domains_watch_schedule')->where('scope','dispatch')->value('alert_at')>$oldAlert,'Additional failed family is not suppressed');
DB::transaction(fn()=>$m->retain('registry',$first));$m->tick($now+91*86400);expect(WatchRegistryObservation::whereKey($first)->exists(),'Notice reference survives retention');expect(WatchRegistryObservation::whereKey($last)->exists(),'Last good survives beyond ninety days');
DB::table('domains_public_evidence_refs')->delete();$m->tick($now+91*86400);expect(!WatchRegistryObservation::whereKey($first)->exists(),'Unreferenced superseded evidence pruned');expect(WatchRegistryObservation::whereKey($last)->exists(),'Last successful fact retained');same(0,DB::table('domains_operations')->count(),'Maintenance never writes financial operations');
// A failed native recipient must not prevent delivery to later eligible staff.
$failure='throw';$levels=[];$markers=[];
App\Models\Notification::creating(static function($n)use(&$failure,&$levels,&$markers,$staff){
    if($n->title!=='Retry inbox'&&$n->title!=='Domain monitoring stopped'){return;}
    $levels[]=DB::transactionLevel();$markers[]=DB::table('domains_watch_schedule')->where('scope','outage')->exists();
    if((int)$n->user_id===(int)$staff[0]->id&&$failure){
        if($failure==='throw'){throw new RuntimeException('Synthetic native inbox failure');}
        return false;
    }
});
foreach(['throw','veto'] as $failure){
    App\Models\Notification::where('title','Retry inbox')->delete();
    try{$sent=\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffAlert::send('Retry inbox','Body','missing.route','admin.domains.watch.view');}catch(Throwable){$sent=false;}
    expect(!$sent,'Failed recipient must not report successful handoff');
    same(1,App\Models\Notification::where('title','Retry inbox')->where('user_id',$staff[1]->id)->count(),'Later recipient still receives alert after '.$failure);
}
$failure='throw';$levels=[];$markers=[];$retryAt=$now+92*86400;$episodeCommitted=false;$commitObservations=[];
\Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionCommitted::class,static function()use(&$episodeCommitted,$retryAt):void {
    if(DB::transactionLevel()===0&&(int)DB::table('domains_watch_schedule')->where('scope','dispatch')->value('alert_at')===$retryAt){$episodeCommitted=true;}
});
App\Models\Notification::creating(static function($n)use(&$episodeCommitted,&$commitObservations):void {
    if($n->title==='Domain monitoring stopped'){$commitObservations[]=$episodeCommitted;}
});
$successfulBefore=App\Models\Notification::where('title','Domain monitoring stopped')->where('user_id',$staff[1]->id)->count();
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['alert_at'=>null,'progress_at'=>$now]);
$old=(array)DB::table('domains_watch_registry')->where('id',$last)->first();unset($old['id']);
$old['result']='unknown';$old['reason']='endpoint_failed';$old['completed_at']=$now;
$oldId=DB::table('domains_watch_registry')->insertGetId($old);
$m->tick($retryAt);
expect($commitObservations!==[]&&!in_array(false,$commitObservations,true),'Every observed recipient delivery follows episode commit');
same([$retryAt,true],[(int)DB::table('domains_watch_schedule')->where('scope','dispatch')->value('alert_at'),!DB::table('domains_watch_registry')->where('id',$oldId)->exists()],'Failure retains episode and still sweeps old observations');
expect($levels!==[]&&array_unique($levels)===[1],'Native inbox writes use a separate receipt transaction after episode locks');
expect(!in_array(false,$markers,true),'Episode committed before any native inbox write');
$outage=fn()=>json_decode(DB::table('domains_watch_schedule')->where('scope','outage')->value('data'),true);
same(true,$outage()['alert_pending']??null,'Failed alert retains durable retry');
$m->tick($retryAt+60);same(true,$outage()['alert_pending']??null,'Repeated partial failure keeps retry pending');
same($successfulBefore+1,App\Models\Notification::where('title','Domain monitoring stopped')->where('user_id',$staff[1]->id)->count(),'Successful recipient receives one alert across partial retries');
$m->tick($retryAt+120);same(true,$outage()['alert_pending']??null,'Escalating retry remains pending before the second due time');
$failure=false;$m->tick($retryAt+180);same(false,$outage()['alert_pending']??null,'Later tick acknowledges successful retry');
same($successfulBefore+1,App\Models\Notification::where('title','Domain monitoring stopped')->where('user_id',$staff[1]->id)->count(),'Recovery skips recipients already delivered');
$count=App\Models\Notification::where('title','Domain monitoring stopped')->count();$m->tick($retryAt+240);
same($count,App\Models\Notification::where('title','Domain monitoring stopped')->count(),'Completed episode stays deduplicated');
// An inbox deletion must not erase durable delivery success during a lost acknowledgement retry.
App\Models\Notification::where('title','Domain monitoring stopped')->where('user_id',$staff[1]->id)->latest('id')->firstOrFail()->delete();
$retry=$outage();$retry['alert_pending']=true;DB::table('domains_watch_schedule')->where('scope','outage')->update(['data'=>json_encode($retry)]);
$m->tick($outage()['alert_retry_at']);same($count-1,App\Models\Notification::where('title','Domain monitoring stopped')->count(),'Inbox deletion does not resend acknowledged recipient delivery');
// Persistent delivery failures back off to one hour without losing their episode.
$failure='veto';$at=$retryAt+1000;
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['alert_at'=>null,'progress_at'=>$now]);
$beforeWarnings=count(array_filter($logs,fn($line)=>$line==='Domain monitoring needs staff review.'));
$m->tick($at);$episode=$outage()['alert_key'];
same(1,$outage()['alert_attempts']??null,'New monitoring episode starts at its first attempt');
$delays=[60,120,240,480,960,1920,3600,3600];
foreach($delays as $delay){
    $before=$outage();same($at+$delay,$before['alert_retry_at'],'Monitoring retry delay is bounded and escalating');
    $m->tick($at+$delay-1);same($before,$outage(),'Monitoring delivery never retries before its due time');
    $at+=$delay;$m->tick($at);
    same($episode,$outage()['alert_key'],'Monitoring retry preserves recipient receipt identity');
}
same(7,$outage()['alert_attempts'],'Monitoring attempt count saturates');
same($beforeWarnings+1,count(array_filter($logs,fn($line)=>$line==='Domain monitoring needs staff review.')),'Only the first attempt logs the episode warning');
$failure=false;$at=$outage()['alert_retry_at'];$m->tick($at);
same(false,$outage()['alert_pending'],'Repaired inbox recovers after capped backoff');
// Missing inbox or receipt storage retains pending delivery with the same backoff.
foreach(['notifications',\Paymenter\Extensions\Servers\Domains\Services\StaffAlertSchema::TABLE]as$unavailable){
    $at+=4000;DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['alert_at'=>null]);
    \Illuminate\Support\Facades\Schema::rename($unavailable,$unavailable.'_missing');
    try{
        $m->tick($at);same(true,$outage()['alert_pending'],'Absent delivery storage keeps the episode pending');
        $before=$outage();$m->tick($at+59);same($before,$outage(),'Absent delivery storage respects the retry deadline');
        $m->tick($at+60);same($at+180,$outage()['alert_retry_at'],'Absent storage uses escalating retry delay');
    }finally{\Illuminate\Support\Facades\Schema::rename($unavailable.'_missing',$unavailable);}
    $at+=180;$m->tick($at);same(false,$outage()['alert_pending'],'Restored delivery storage recovers pending monitoring alerts');
}
// Even a failed warning sink leaves a durable lease and does not stop maintenance.
$throwAlertLogs=true;
\Illuminate\Support\Facades\Log::listen(static function($event)use(&$throwAlertLogs):void {
    if($throwAlertLogs&&in_array($event->message,['Domain monitoring needs staff review.','Domain monitoring alert will be retried.'],true)){throw new RuntimeException('Synthetic warning sink failure');}
});
$at+=4000;DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['alert_at'=>null]);$m->tick($at);
same(true,$outage()['alert_pending'],'Warning failure retains pending delivery');
$throwAlertLogs=false;$at+=60;$m->tick($at);same(false,$outage()['alert_pending'],'Warning failure recovers on the due retry');
// Legacy, malformed and oversized attempt counts always have a bounded retry.
foreach([null,-1,'bad',[],PHP_INT_MAX]as$attempts){
    $state=$outage();$state['alert_pending']=true;$state['alert_retry_at']=0;
    if($attempts===null){unset($state['alert_attempts']);}else{$state['alert_attempts']=$attempts;}
    DB::table('domains_watch_schedule')->where('scope','outage')->update(['data'=>json_encode($state)]);
    $failure='veto';$m->tick(++$at);
    $expected=$attempts===PHP_INT_MAX?3600:60;
    same($at+$expected,$outage()['alert_retry_at'],'Legacy or corrupt attempt count remains bounded');
}
// A newer delivery lease cannot be acknowledged by an earlier sender.
$failure=false;$replaceEpisode=false;
App\Models\Notification::creating(static function($n)use(&$replaceEpisode,$outage):void {
    if($replaceEpisode&&$n->title==='Domain monitoring stopped'){
        $replaceEpisode=false;$state=$outage();$state['alert_attempt']='replacement';
        DB::table('domains_watch_schedule')->where('scope','outage')->update(['data'=>json_encode($state)]);
    }
});
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$at]);$m->tick($at);
same(false,$outage()['alert_pending'],'Healthy monitoring clears pending alert');
$replaceEpisode=true;$m->tick($at+600);
same(true,$outage()['alert_pending'],'Lost acknowledgement keeps newer pending lease');
same('replacement',$outage()['alert_attempt'],'Old sender never overwrites a replacement token');
same(1,$outage()['alert_attempts'],'Recovered monitoring rearms a fresh episode');
expect($episode!==$outage()['alert_key'],'Recovered monitoring uses a new episode receipt identity');
$m->tick($at+660);same(false,$outage()['alert_pending'],'Replacement retries recover without duplicate receipts');

$nativeSuccessful=true;echo "Two missed turns, day-long outage, deduplicated health and ninety-day retained last-good/notice evidence passed.\n";
