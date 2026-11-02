<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\NoticeHealth;
use Paymenter\Extensions\Servers\Domains\Models\WatchAudit;
use Paymenter\Extensions\Servers\Domains\Services\WatchNoticeHealth;
// Native persistence is needed: encrypted audit data, app-zone casts and accepted delivery joins cause these bugs.
$f=noticeFixture();extract($f);
Auth::login(staffUser(['admin.domains.view','admin.domains.watch.view']));
$failures=[];
$check=static function(bool $ok,string $message)use(&$failures):void{if(!$ok){$failures[]=$message;}};
$record=static function(string $kind,int $target,int $at,array $extra=[])use($uid):void {
    WatchAudit::create(['after_revision'=>hash('sha256',(string)$at),'subject_type'=>'public_notice','subject_id'=>$target,'user_id'=>$uid,
        'event'=>'notice_health','reason'=>'Synthetic notice failure','data'=>['target_kind'=>$kind,'code'=>'render_failed','channel'=>'mail','template'=>'domains_watch_expiring']+$extra,
        'created_at'=>now()->setTimestamp($at)]);
};
// Watch and managed IDs can collide; the other kind must not use up this kind's twenty records.
$target=$id+100;$record('watch',$target,$now);
for($i=1;$i<=21;$i++){$record('managed',$target,$now+$i);}
$check(NoticeHealth::open('watch',$target,'example.com')!==null,'Other target kinds must not hide the open render failure');
// A failure written outside UTC must retain the supplied instant and close after its own channel succeeds.
noticeRegistry($f,$now);$ids=$planner->plan('watch',$id,$uid,$now+2);
$zone=date_default_timezone_get();$appZone=config('app.timezone');
try{
    date_default_timezone_set('America/Los_Angeles');config(['app.timezone'=>'America/Los_Angeles']);
    WatchNoticeHealth::record($uid,$id,'watch','render_failed',$now+3,['channel'=>'mail','template'=>'domains_watch_expiring']);
    $audit=WatchAudit::where('subject_type','public_notice')->where('subject_id',$id)->where('event','notice_health')->latest('id')->firstOrFail();
    $check($audit->created_at->getTimestamp()===$now+3,'Health timestamp must round-trip in the app timezone');
    $claim=$delivery->reserve($ids[0],'mail',$now+4);expect($claim!==null&&$delivery->handoff($claim,$now+4),'Synthetic mail recovery accepted');
    $open=NoticeHealth::open('watch',$id,'example.com');
    $check($open===null||!str_contains($open['text'],'template has an error'),'Later accepted mail must close the timezone-adjusted render failure');
}finally{date_default_timezone_set($zone);config(['app.timezone'=>$appZone]);}
// The saved cause is historical; the next step follows the current mail setting until delivery is confirmed.
$target=$id+200;WatchNoticeHealth::record($uid,$target,'watch','render_failed',$now+10,['channel'=>'mail','template'=>'domains_watch_expiring','mail_paused'=>true]);
config(['settings.mail_disable'=>true]);
$check(str_contains(NoticeHealth::open('watch',$target,'example.com')['text'],'Turn off Disable Mail in Settings.'),'Paused mail names the current setting');
config(['settings.mail_disable'=>false]);$text=NoticeHealth::open('watch',$target,'example.com')['text'];
$check(str_contains($text,'Mail is on. Delivery is not confirmed yet.')&&!str_contains($text,'Turn off Disable Mail'),'Restored mail must not repeat the stale mail-off step');
expect($failures===[],implode('; ',$failures));
echo "Notice health keeps target kinds separate, preserves instants and follows current mail settings.\n";
$nativeSuccessful=true;
