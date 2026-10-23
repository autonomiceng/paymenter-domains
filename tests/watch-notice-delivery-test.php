<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use App\Models\{Notification,NotificationTemplate,EmailLog};
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticePreferences as Pref,WatchNoticeDelivery,WatchNotices,WatchNoticeReview};
use Paymenter\Extensions\Servers\Domains\Models\{WatchNotice,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Mail\WatchNotice as NoticeMail;
$f=noticeFixture();extract($f);noticeRegistry($f,$now);$at=$now+2;
same([], (new WatchNotices())->plan('watch',$id,$uid,$at),'Default planning dormant');
$ids=$planner->plan('watch',$id,$uid,$at);same(1,count($ids),'One nearest expiry notice');$n=WatchNotice::findOrFail($ids[0]);
same('registry',$n->family,'Evidence in registry table');expect(DB::table('domains_public_evidence_refs')->where('family','registry')->where('observation_id',$n->observation_id)->exists(),'Notice pins evidence');
same([],array_diff(array_keys(WatchNoticeDelivery::data($n)),['domain','date','company','domain_url']),'Template data whitelist');
config(['settings.company_name'=>'<script>company</script>']);$nativeBefore=Notification::where('user_id',$uid)->count();
$app=$delivery->reserve($n->id,'app',$at);$mail=$delivery->reserve($n->id,'mail',$at);expect($app!==null && $mail!==null && $app!==$mail,'Independent committed channel claims');
$fail=true;Notification::creating(static function($r)use(&$fail,$uid){if($fail && (int)$r->user_id===$uid){throw new RuntimeException('Synthetic app failure');}});
expect($delivery->handoff($mail,$at),'Mail queued');expect(!$delivery->handoff($app,$at),'App failure independent');$fail=false;
same('accepted',DB::table('domains_watch_deliveries')->find($mail)->state,'Accepted means queued');same('uncertain',DB::table('domains_watch_deliveries')->find($app)->state,'Exception uncertain');
same(['mail'=>false,'app'=>false],$delivery->deliver($n->id,$at),'Neither accepted nor uncertain replays');
same(1,Mail::queued(NoticeMail::class)->count(),'Mail exactly once despite app failure');same($nativeBefore,Notification::where('user_id',$uid)->count(),'Failed app not fabricated');
$m=Mail::queued(NoticeMail::class)->sole();expect(str_contains($m->render(),'example.com') && str_contains($m->render(),$m->data['date']),'Mail names domain and relevant date');
expect(!str_contains($m->render(),'https://rdap.example.com'),'Registry endpoint not in notice');expect(!str_contains($m->render(),'<script>company'),'Company escaped');
$text=view('domains::mail-text',$m->content()->with)->render();expect(str_contains($text,'example.com')&&str_contains($text,$m->data['date'])&&str_contains($text,'View domain checks ('),'Readable text alternative includes safe detail link');
// Current owner, native preference and target pause are rechecked after reservation.
noticeDns($f,$now);noticeDns($f,$now+900);$ids=$planner->plan('watch',$id,$uid,$now+902);$drift=WatchNotice::where('kind','ns_changed')->sole();
$pending=$delivery->reserve($drift->id,'app',$now+902);expect($pending!==null,'Pending app reserved');
$p=Pref::read($uid,'example.com');DB::transaction(fn()=>Pref::save($uid,'example.com',$p['account']['revision'],$p['target']['revision'],false,true));
same('suppressed',DB::table('domains_watch_deliveries')->find($pending)->state,'Opt-out immediately suppresses pending app');expect(!$delivery->handoff($pending,$now+903),'Opt-out handoff refuses');
expect(WatchNotice::whereKey($drift->id)->where('state','open')->exists(),'Portal condition survives opt-out');
$state=WatchNotices::state(Pref::scope($uid,'example.com'));same(['ns_changed'],$state['portal']['dns'],'Retained portal facts');
try { DB::transaction(fn()=>Pref::save($uid,'example.com',1,1,true,true));expect(false,'Stale revision must refuse'); } catch(DomainException) {}
$p=Pref::read($uid,'example.com');DB::transaction(fn()=>Pref::save($uid,'example.com',$p['account']['revision'],$p['target']['revision'],true,true));
$next=$delivery->reserve($drift->id,'mail',$now+904);expect($next!==null,'Separate drift mail claim');expect(!$delivery->handoff($next,$now+904),'One owner/name mail per UTC day');
// A capped lower-priority claim remains eligible next day with fresh complete proof.
$tomorrow=$now+86400;noticeDns($f,$tomorrow);noticeDns($f,$tomorrow+900);$tomorrow+=900;$planner->plan('watch',$id,$uid,$tomorrow+2);
expect($delivery->handoff($next,$tomorrow+2),'Capped drift mail eligible next day');same(2,Mail::queued(NoticeMail::class)->count(),'Exactly one mail on each day');
// Staff cannot replay the old key; native evidence/reason/role are mandatory and rechecked.
$again=$tomorrow+86400;noticeDns($f,$again);noticeDns($f,$again+900);$again+=900;$planner->plan('watch',$id,$uid,$again+2);
$staff=staffUser(['admin.domains.view','admin.domains.watch.manage']);$inspection=WatchNoticeReview::inspect($staff,$next);expect($inspection['native_id']!==null,'Native email evidence retained');
foreach([str_repeat('é',9),str_repeat('é',501),"invalid\xff\xff\xff"] as $bad){$refused=false;try{WatchNoticeReview::resend($staff,$next,(int)$inspection['native_id'],$bad,str_repeat('e',64),$again+3,$delivery);}catch(DomainException){$refused=true;}expect($refused,'Resend character length and UTF-8 required');}
$resend=WatchNoticeReview::resend($staff,$next,(int)$inspection['native_id'],str_repeat('🦦',500),str_repeat('e',64),$again+3,$delivery);expect($resend!==null && $resend!==$next,'New audited resend identity');
$staff->role->update(['permissions'=>[]]);expect(!$delivery->handoff($resend,$again+3),'Revoked staff role suppresses pending resend');
same('suppressed',DB::table('domains_watch_deliveries')->find($resend)->state,'Role revocation suppresses rather than caps');
same('accepted',DB::table('domains_watch_deliveries')->find($mail)->state,'Original accepted evidence unchanged');
same(1,WatchAudit::where('event','notice_resend')->count(),'Resend audited');
// Installing again preserves staff-customized native template content and channel policy.
$template=NotificationTemplate::where('key','domains_watch_expiring')->sole();$template->update(['body'=>'Synthetic retained template {{ $domain }} {{ $date }}','mail_enabled'=>'never']);
\Paymenter\Extensions\Servers\Domains\Services\WatchNoticeTemplates::ensure();same('Synthetic retained template {{ $domain }} {{ $date }}',$template->fresh()->body,'Customized template retained');same('never',$template->fresh()->mail_enabled->value,'Customized channel policy retained');
// Owner transfer invalidates current target immediately, without touching the portal history.
$fresh=$again+86400;noticeRegistry($f,$fresh);$new=$planner->plan('watch',$id,$uid,$fresh+2);$unclaimed=WatchNotice::findOrFail(end($new));
$service->update(['user_id'=>$other->id]);expect($delivery->reserve($unclaimed->id,'app',$fresh+3)===null,'Changed source owner refuses');
$nativeSuccessful=true;echo "Independent channel claims, uncertain no-replay, preferences, daily cap, audited resend and safe native templates passed.\n";
