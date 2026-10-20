<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticeSchema,WatchNoticeTemplates,WatchNotices,WatchNoticeDelivery,WatchNoticePreferences as Pref,WatchDnsChecks};
use Paymenter\Extensions\Servers\Domains\Models\{WatchNotice,ManagedPublicObservation};
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
use Paymenter\Extensions\Servers\Domains\Mail\WatchNotice as NoticeMail;
$prior=null;$failApp=true;$planner=new WatchNotices(true);$delivery=new WatchNoticeDelivery(true);
$f=managedFixture(skipA3:true,beforeConfirm:function(array $f)use(&$prior,&$failApp,$planner,$delivery):void {
    extract($f);global $fixture;require __DIR__.'/Support/reminder-mail.php';app('view')->prependNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
    \Paymenter\Extensions\Servers\Domains\Services\WatchScheduleSchema::install();WatchNoticeSchema::install();WatchNoticeTemplates::ensure();$uid=(int)$owner->id;$old=$clock->now()->getTimestamp()-7200;$checks=new WatchDnsChecks(true);
    DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','delegation')->update(['due_at'=>$old+900]);
    $t=$checks->begin($watch->id,$uid,'delegation',$old+900);expect($t!==null,'Second watch evidence');expect($checks->finish($t,new DnsReading('delegation','external'),$old+901),'Confirm prior watch drift');
    $ids=$planner->plan('watch',$watch->id,$uid,$old+902);same(1,count($ids),'Prior watch episode');
    \App\Models\Notification::creating(static function()use(&$failApp){if($failApp){throw new RuntimeException('Synthetic uncertain app');}});
    $prior=WatchNotice::findOrFail($ids[0]);expect($delivery->deliver($prior->id,$old+902)['mail'],'Prior mail accepted');$failApp=false;
    same('uncertain',DB::table('domains_watch_deliveries')->where('channel','app')->value('state'),'Prior app uncertain');
});extract($f);$uid=(int)$owner->id;
expect($signals->enqueue($d->id,$uid,$time->now),'Relink accepted');same('suppressed',$prior->fresh()->state,'Old portal notice suppressed at relink');
same([], $planner->plan('managed',$d->id,$uid,$time->now),'Predecessor evidence cannot create managed notice');
$fake->answers['example.com|NS']=managedNs('ns.external.test');expect($signals->observe($d->id,$uid,'delegation'),'First independent managed mismatch');
same([], $planner->plan('managed',$d->id,$uid,$time->now+1),'First mismatch does not borrow watch confirmation');
$time->now+=900;managedDue($d->id,$time->now);expect($signals->observe($d->id,$uid,'delegation'),'Second independent managed mismatch');
$ids=$planner->plan('managed',$d->id,$uid,$time->now+1);same(1,count($ids),'Managed episode continuation notice');$n=WatchNotice::findOrFail($ids[0]);
same($prior->episode_key,$n->episode_key,'Target-independent episode key');same('managed_dns',$n->family,'Own managed evidence family');
expect(ManagedPublicObservation::whereKey($n->observation_id)->exists(),'Managed observation belongs to managed family');
same(['mail'=>false,'app'=>false],$delivery->deliver($n->id,$time->now+1),'Relink never replays accepted channels');same(1,Mail::queued(NoticeMail::class)->count(),'Exactly one drift mail across relink');
$fake->answers['example.com|NS']=managedNs();$time->now+=900;managedDue($d->id,$time->now);expect($signals->observe($d->id,$uid,'delegation'),'First managed matching check');
$planner->plan('managed',$d->id,$uid,$time->now+1);same(0,WatchNotice::where('kind','ns_restored')->count(),'One match does not restore');
$time->now+=900;managedDue($d->id,$time->now);expect($signals->observe($d->id,$uid,'delegation'),'Second managed matching check');
$planner->plan('managed',$d->id,$uid,$time->now+1);$restored=WatchNotice::where('kind','ns_restored')->sole();same('managed_dns',$restored->family,'Restoration has new managed evidence');
same(['mail'],$restored->data['channels'],'Only accepted prior drift channel permits restoration; uncertain app does not');same(0,WatchNotice::whereIn('kind',['expired','expiring'])->where('target_kind','managed')->count(),'Managed targets never get public expiry notices');
$nativeSuccessful=true;echo "Managed relink uses independent proof, retains episode/channel identity and restores only prior channels.\n";
