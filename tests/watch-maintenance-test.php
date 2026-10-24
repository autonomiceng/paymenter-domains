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
$failure='throw';$levels=[];$markers=[];$retryAt=$now+92*86400;
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['alert_at'=>null,'progress_at'=>$now]);
$old=(array)DB::table('domains_watch_registry')->where('id',$last)->first();unset($old['id']);
$old['result']='unknown';$old['reason']='endpoint_failed';$old['completed_at']=$now;
$oldId=DB::table('domains_watch_registry')->insertGetId($old);
$m->tick($retryAt);
same([$retryAt,true],[(int)DB::table('domains_watch_schedule')->where('scope','dispatch')->value('alert_at'),!DB::table('domains_watch_registry')->where('id',$oldId)->exists()],'Failure retains episode and still sweeps old observations');
expect($levels!==[]&&array_unique($levels)===[0],'Native inbox writes happen outside episode locks');
expect(!in_array(false,$markers,true),'Episode committed before any native inbox write');
$outage=fn()=>json_decode(DB::table('domains_watch_schedule')->where('scope','outage')->value('data'),true);
same(true,$outage()['alert_pending']??null,'Failed alert retains durable retry');
$failure=false;$m->tick($retryAt+60);same(false,$outage()['alert_pending']??null,'Later tick acknowledges successful retry');
$count=App\Models\Notification::where('title','Domain monitoring stopped')->count();$m->tick($retryAt+120);
same($count,App\Models\Notification::where('title','Domain monitoring stopped')->count(),'Completed episode stays deduplicated');
$nativeSuccessful=true;echo "Two missed turns, day-long outage, deduplicated health and ninety-day retained last-good/notice evidence passed.\n";
