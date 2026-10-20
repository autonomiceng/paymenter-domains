<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticeSchema,WatchNoticeTemplates,WatchNotices,WatchNoticeDelivery,WatchDnsChecks};
use Paymenter\Extensions\Servers\Domains\Models\WatchNotice;
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
$prior=null;$app=null;$mail=null;$planner=new WatchNotices(true);$delivery=new WatchNoticeDelivery(true);
$f=managedFixture(skipA3:true,beforeConfirm:function(array $f)use(&$prior,&$app,&$mail,$planner,$delivery):void {
    extract($f);global $fixture;require __DIR__.'/Support/reminder-mail.php';app('view')->prependNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
    \Paymenter\Extensions\Servers\Domains\Services\WatchScheduleSchema::install();WatchNoticeSchema::install();WatchNoticeTemplates::ensure();$uid=(int)$owner->id;$old=$clock->now()->getTimestamp()-7200;$checks=new WatchDnsChecks(true);
    DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','delegation')->update(['due_at'=>$old+900]);
    $t=$checks->begin($watch->id,$uid,'delegation',$old+900);expect($t!==null,'Second predecessor sample');$checks->finish($t,new DnsReading('delegation','external'),$old+901);
    $ids=$planner->plan('watch',$watch->id,$uid,$old+902);$prior=WatchNotice::findOrFail($ids[0]);
    $app=$delivery->reserve($prior->id,'app',$old+902);$mail=$delivery->reserve($prior->id,'mail',$old+902);expect($app!==null && $mail!==null,'Unhanded predecessor claims');
});extract($f);$uid=(int)$owner->id;
expect($signals->enqueue($d->id,$uid,$time->now),'Relink successor');$fake->answers['example.com|NS']=managedNs('ns.external.test');
$signals->observe($d->id,$uid,'delegation');$planner->plan('managed',$d->id,$uid,$time->now+1);
$time->now+=900;managedDue($d->id,$time->now);$signals->observe($d->id,$uid,'delegation');$ids=$planner->plan('managed',$d->id,$uid,$time->now+1);$n=WatchNotice::findOrFail($ids[0]);
// Force the precise interleaving: the old worker has read its notice when template preparation rebinds the claim.
DB::table('domains_watch_deliveries')->where('id',$app)->update(['state'=>'reserved','safe_code'=>null]);$move=true;
\App\Models\NotificationTemplate::retrieved(static function()use(&$move,$delivery,$n,$time,$app):void {
    if (!$move) { return; }$move=false;same($app,$delivery->reserve($n->id,'app',$time->now+1),'Successor reuses unhanded identity');
});
expect(!$delivery->handoff($app,$time->now+1),'Stale predecessor worker stops');$claim=DB::table('domains_watch_deliveries')->find($app);
same((int)$n->id,(int)$claim->notice_id,'Claim belongs to successor');same('reserved',$claim->state,'Stale worker cannot suppress rebound claim');
expect($delivery->handoff($app,$time->now+2),'Current successor worker hands off once');same($mail,$delivery->reserve($n->id,'mail',$time->now+2),'Suppressed unhanded mail follows successor');
expect($delivery->handoff($mail,$time->now+2),'Successor mail accepted');expect(!$delivery->handoff($mail,$time->now+3),'No replay');
same(1,Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\WatchNotice::class)->count(),'One successor mail');
$nativeSuccessful=true;echo "Reserved claims relink without replay; stale predecessor cannot suppress a successor claim.\n";
