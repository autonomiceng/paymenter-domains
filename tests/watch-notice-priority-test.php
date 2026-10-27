<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchNotice;
$f=noticeFixture();extract($f);noticeDns($f,$now);noticeDns($f,$now+900);$planner->plan('watch',$id,$uid,$now+902);
$drift=WatchNotice::where('kind','ns_changed')->sole();$pending=$delivery->reserve($drift->id,'mail',$now+902);
noticeRegistry($f,$now+903);$planner->plan('watch',$id,$uid,$now+905);$urgent=WatchNotice::where('kind','expiring')->sole();
expect(!$delivery->handoff($pending,$now+906),'Unclaimed urgent expiry outranks drift');same('reserved',DB::table('domains_watch_deliveries')->find($pending)->state,'Lower priority remains pending');
// A template channel disabled by staff must not block a still-enabled lower-priority kind.
NotificationTemplate::where('key','domains_watch_expiring')->sole()->update(['mail_enabled'=>'never']);
expect($delivery->handoff($pending,$now+907),'Disabled urgent channel does not block drift');
// A notice already reserved before a long outage must not backfill a superseded threshold.
$far=$now+100*86400;noticeRegistry($f,$far,90);$ids=$planner->plan('watch',$id,$uid,$far+2);$old=WatchNotice::findOrFail(end($ids));
NotificationTemplate::where('key','domains_watch_expiring')->sole()->update(['mail_enabled'=>'choice_on']);$claim=$delivery->reserve($old->id,'mail',$far+3);expect($claim!==null,'Old 90-day claim reserved');
$later=$far+83*86400;noticeRegistry($f,$later,7);$planner->plan('watch',$id,$uid,$later+2);
expect(!$delivery->handoff($claim,$later+3),'Superseded 90-day claim cannot send at 7 days');same('resolved',$old->fresh()->state,'Portal replaces older window');
$current=WatchNotice::where('kind','expiring')->where('state','open')->sole();same(7,$current->threshold,'Only nearest current window remains');
// Handoff cannot consume a day while the injected clock is behind the reservation.
$claim=$delivery->reserve($current->id,'mail',$later+4);expect(!$delivery->handoff($claim,$later+3),'Clock reversal handoff defers');same('reserved',DB::table('domains_watch_deliveries')->find($claim)->state,'Clock reversal consumes no claim');
$nativeSuccessful=true;echo "Urgent priority, disabled native channel, superseded window and clock reversal delivery passed.\n";
