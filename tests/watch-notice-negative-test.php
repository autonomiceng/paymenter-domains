<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use App\Models\{NotificationTemplate,NotificationPreference};
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticePreferences as Pref,WatchNoticeDelivery,WatchNotices};
use Paymenter\Extensions\Servers\Domains\Models\{WatchNotice,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Mail\WatchNotice as NoticeMail;
$f=noticeFixture();extract($f);$step=0;
$new=function()use($f,$planner,&$step,$now,$id,$uid):array {
    $at=$now+(++$step)*86400;noticeRegistry($f,$at);$ids=$planner->plan('watch',$id,$uid,$at+2);
    return [WatchNotice::findOrFail(end($ids)),$at+3];
};
[$n,$at]=$new();$template=NotificationTemplate::where('key','domains_watch_expiring')->sole();
$template->update(['body'=>'@php throw new \\RuntimeException("Synthetic render failure"); @endphp']);
$before=DB::table('domains_watch_deliveries')->count();$health=WatchAudit::where('event','notice_health')->count();
expect($delivery->reserve($n->id,'mail',$at)===null,'Render failure does not claim');expect($delivery->reserve($n->id,'mail',$at)===null,'Repeated render failure safe');
same($before,DB::table('domains_watch_deliveries')->count(),'No delivery identity on render error');same($health+1,WatchAudit::where('event','notice_health')->count(),'Render health deduplicated');
$template->update(['body'=>'{{ $domain }} {{ $date }} <a href="{{ $domain_url }}">View domain checks</a>']);
$claim=$delivery->reserve($n->id,'mail',$at);expect($claim!==null,'Fixed template can claim');
$nativePref=NotificationPreference::create(['user_id'=>$uid,'notification_template_id'=>$template->id,'mail_enabled'=>false,'in_app_enabled'=>true]);
expect(!$delivery->handoff($claim,$at),'Native opt-out after reservation suppresses mail');
$app=$delivery->reserve($n->id,'app',$at);expect($app!==null && $delivery->handoff($app,$at),'Independent native app preference honored');
[$n,$at]=$new();$nativePref->update(['mail_enabled'=>true,'in_app_enabled'=>false]);
$r=$delivery->deliver($n->id,$at);same(['mail'=>true,'app'=>false],$r,'Native mail on app off independent');
[$n,$at]=$new();$nativePref->update(['in_app_enabled'=>true]);$template->update(['enabled'=>false]);
same(['mail'=>false,'app'=>false],$delivery->deliver($n->id,$at),'Disabled template suppresses both channels');
[$n,$at]=$new();$template->update(['enabled'=>true]);config(['settings.mail_disable'=>true]);
same(['mail'=>false,'app'=>true],$delivery->deliver($n->id,$at),'Global mail pause leaves app independent');config(['settings.mail_disable'=>false]);
// Staff can force mail on watch templates; app delivery must not consume its mail identity.
[$n,$at]=$new();$template->update(['mail_enabled'=>'force']);config(['settings.mail_disable'=>true]);
$queued=Mail::queued(NoticeMail::class)->count();$health=WatchAudit::where('event','notice_health')->count();
same(['mail'=>false,'app'=>true],$delivery->deliver($n->id,$at),'Forced watch mail pause preserves independent app delivery');
same(0,DB::table('domains_watch_deliveries')->where('notice_id',$n->id)->where('channel','mail')->count(),'Unavailable forced watch mail consumes no delivery identity');
same($health+1,WatchAudit::where('event','notice_health')->count(),'Unavailable forced watch mail alerts staff');
config(['settings.mail_disable'=>false]);$claim=$delivery->reserve($n->id,'mail',$at);expect($claim!==null,'Restored forced watch mail can reserve');
config(['settings.mail_disable'=>true]);expect(!$delivery->handoff($claim,$at),'Forced watch mail paused after reservation waits');
same('reserved',DB::table('domains_watch_deliveries')->find($claim)->state,'Mail pause preserves reserved forced watch claim');
same($queued,Mail::queued(NoticeMail::class)->count(),'Paused forced watch mail queues nothing');
config(['settings.mail_disable'=>false]);expect($delivery->handoff($claim,$at),'Restored forced watch channel sends reserved mail');
same($queued+1,Mail::queued(NoticeMail::class)->count(),'Restored forced watch mail queues once');
$template->update(['mail_enabled'=>'choice_on']);
[$n,$at]=$new();$claim=$delivery->reserve($n->id,'mail',$at);$template->update(['subject'=>'Changed {{ $domain }} {{ $date }}']);
// Handoff re-renders current staff templates before its final recheck.
expect($delivery->handoff($claim,$at),'Current edited template rendered at handoff');
[$n,$at]=$new();$claim=$delivery->reserve($n->id,'app',$at);$p=Pref::read($uid,'example.com');
DB::transaction(fn()=>Pref::save($uid,'example.com',$p['account']['revision'],$p['target']['revision'],true,false));
expect(!$delivery->handoff($claim,$at),'Per-target pause suppresses reserved app');expect(WatchNotice::find($n->id)->state==='open','Pause keeps portal history');
$p=Pref::read($uid,'example.com');DB::transaction(fn()=>Pref::save($uid,'example.com',$p['account']['revision'],$p['target']['revision'],true,true));
// Queue accepted then threw: durable uncertainty forbids replay even if native queue accepted.
[$n,$at]=$new();$fake=Mail::getFacadeRoot();$count=0;
Mail::swap(new class($fake,$count) {
    public function __construct(private $fake,public int &$count) {}
    public function render($view,$data=[]){return $this->fake->render($view,$data);}public function mailer($name=null){return $this->fake->mailer($name);}public function to($address) { return $this; }public function cc($v){return $this;}public function bcc($v){return $this;}
    public function queue($mail):void { $this->fake->to('synthetic@example.test')->queue($mail);$this->count++;throw new RuntimeException('Synthetic lost queue acknowledgement'); }
});
$claim=$delivery->reserve($n->id,'mail',$at);expect(!$delivery->handoff($claim,$at),'Lost queue acknowledgment uncertain');
same('uncertain',DB::table('domains_watch_deliveries')->find($claim)->state,'Uncertain retained');same(1,$count,'Native queue called once');
expect($delivery->reserve($n->id,'mail',$at)===null && !$delivery->handoff($claim,$at),'Uncertain never auto replays');same(1,$count,'No second queue call');Mail::swap($fake);
// Current locked profile revision invalidates old confirmation, even though lineage ID is stable.
noticeDns($f,$at+900);noticeDns($f,$at+1800);$planner->plan('watch',$id,$uid,$at+1802);$drift=WatchNotice::where('kind','ns_changed')->sole();
$claim=$delivery->reserve($drift->id,'app',$at+1803);expect($claim!==null,'Current profile claim reserved');
DB::table('domains_watch_profiles')->where('id',$profile->id)->increment('revision');
expect(!$delivery->handoff($claim,$at+1804),'Changed profile refuses prior confirmation at handoff');
// Retiring a watch or changing native ownership suppresses already reserved work.
[$n,$at]=$new();$claim=$delivery->reserve($n->id,'mail',$at);DB::table('domains_watch_names')->where('id',$id)->update(['state'=>'retired']);
expect(!$delivery->handoff($claim,$at),'Retired target suppresses handoff');
$nativeSuccessful=true;echo "Render-before-claim, native preferences, template pause, target pause and queue-after-accept failure passed.\n";
