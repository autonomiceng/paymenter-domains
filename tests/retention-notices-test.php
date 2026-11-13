<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
require_once __DIR__.'/Support/psl.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryRetention,PublicSuffixList,PslTransport,PslValidator,RetentionSchema,WatchDiscovery,WatchNotices,WatchNoticePreferences};
use Paymenter\Extensions\Servers\Domains\Models\WatchNotice;
$f=noticeFixture();extract($f,EXTR_SKIP);noticeRegistry($f,$now);
$ids=$planner->plan('watch',$id,$uid,$now+2);expect($ids!==[],'Real notice produced');$noticeId=$ids[0];
$deliveryId=$delivery->reserve($noticeId,'app',$now+2);expect($deliveryId!==null&&$delivery->handoff($deliveryId,$now+3),'Real inbox handoff accepted');
$notice=DB::table('domains_watch_notices')->find($noticeId);$sent=DB::table('domains_watch_deliveries')->find($deliveryId);
DB::table('domains_watch_notices')->where('id',$noticeId)->update(['state'=>'resolved','resolved_at'=>$now+10]);
$at=Carbon\CarbonImmutable::createFromTimestampUTC($now+10)->addMonthsNoOverflow(13)->getTimestamp()+1;
if(is_file(dirname(__DIR__).'/Domains/Services/HistoryRetention.php')){
    DB::unprepared("CREATE TRIGGER retention_delivery_fail BEFORE DELETE ON domains_watch_deliveries BEGIN SELECT RAISE(ABORT, 'synthetic move failure'); END");
    try{HistoryRetention::sweep('deliveries',$at);expect(false,'Delivery move must fail');}catch(Illuminate\Database\QueryException $e){expect(str_contains($e->getMessage(),'synthetic move failure'),'Expected move failure');}
    DB::unprepared('DROP TRIGGER retention_delivery_fail');
    expect(!HistoryRetention::replayed('delivery',$sent->delivery_key),'Failed move does not commit replay key separately');
    expect(DB::table('domains_watch_deliveries')->where('id',$deliveryId)->exists(),'Failed move preserves active delivery');
    expect(!DB::table(RetentionSchema::COLD)->where('family','deliveries')->where('source_id',$deliveryId)->exists(),'Failed move has no cold copy');
    DB::table(RetentionSchema::SCANS)->where('family','deliveries')->update(['cursor'=>0]);
    HistoryRetention::tick($at);
}
expect(!DB::table('domains_watch_notices')->where('id',$noticeId)->exists(),'Closed ordinary notice moves out of active history');
expect(!DB::table('domains_watch_deliveries')->where('id',$deliveryId)->exists(),'Accepted delivery moves with its closed notice');
expect(HistoryRetention::replayed('notice',$notice->notice_key)&&HistoryRetention::replayed('delivery',$sent->delivery_key),'Replay keys survive history movement');
DB::transaction(fn()=>WatchNotices::write($notice->scope,$uid,[],$now+2));
same([],$planner->plan('watch',$id,$uid,$now+2),'An old episode cannot recreate an archived notice');
// Retrying a delivery against another notice in the same episode still sees the old target-independent key.
$copy=(array)$notice;unset($copy['id']);$copy['notice_key']=str_repeat('f',64);$copy['state']='open';$copy['resolved_at']=null;
$replacement=DB::table('domains_watch_notices')->insertGetId($copy);
same(null,$delivery->reserve($replacement,'app',$now+2),'Archived delivery key refuses a second handoff');
DB::table('domains_watch_deliveries')->insert([...array_diff_key((array)$sent,['id'=>true]),'notice_id'=>$replacement,'delivery_key'=>str_repeat('e',64),'state'=>'uncertain','accepted_at'=>null]);
DB::table('domains_watch_notices')->where('id',$replacement)->update(['state'=>'resolved','resolved_at'=>$now+10]);
HistoryRetention::tick($at);HistoryRetention::tick($at);
expect(DB::table('domains_watch_notices')->where('id',$replacement)->exists(),'Uncertain delivery keeps its parent active');
expect(DB::table('domains_watch_deliveries')->where('state','uncertain')->exists(),'Uncertain acceptance never expires automatically');
// A paused DNS episode retains its accepted channel after active receipts are archived.
$phaseClock=new PslClock($now);$validator=new PslValidator(128,5,1);
$psl=new PublicSuffixList($phaseClock,new PslTransport(fn()=>['status'=>200,'body'=>pslFixture()]),$validator);
same('updated',$psl->update(),'Synthetic suffix list supports the discovery lifecycle');
$discovery=new WatchDiscovery($psl,$validator,$phaseClock);$discovery->discover((int)$service->id,(int)$product->id);
noticeDns($f,$now);noticeDns($f,$now+900);$planner->plan('watch',$id,$uid,$now+902);
$drift=WatchNotice::where('watch_id',$id)->where('kind','ns_changed')->sole();
$accepted=$delivery->reserve($drift->id,'app',$now+903);
expect($accepted!==null&&$delivery->handoff($accepted,$now+903),'DNS outage notice reaches the inbox');
$planner->plan('watch',$id,$uid,$now+904);$scope=WatchNoticePreferences::scope($uid,'example.com');
same(['app'],WatchNotices::state($scope)['dns']['delivered'],'The active episode remembers its accepted channel');
$phaseClock->at=$now+905;$service->update(['status'=>'suspended']);$discovery->discover((int)$service->id,(int)$product->id);
same('paused',$watch->fresh()->state,'Suspended hosting pauses the watch');
same('resolved',$drift->fresh()->state,'Pausing closes the handed-off outage notice');
$resume=Carbon\CarbonImmutable::createFromTimestampUTC($now+905)->addMonthsNoOverflow(13)->getTimestamp()+1;
HistoryRetention::tick($resume);
expect(!DB::table('domains_watch_deliveries')->where('id',$accepted)->exists(),'The closed outage delivery leaves active storage');
expect(DB::table(RetentionSchema::COLD)->where('family','deliveries')->where('source_id',$accepted)->exists(),'The accepted outage receipt remains in cold storage');
$phaseClock->at=$resume;$psl->update();$service->update(['status'=>'active']);$discovery->discover((int)$service->id,(int)$product->id);
same('active',$watch->fresh()->state,'Restored hosting resumes the watch');
$dns->enroll($id,$uid,$resume);noticeDns($f,$resume);noticeDns($f,$resume+900);$planner->plan('watch',$id,$uid,$resume+902);
same(['app'],WatchNotices::state($scope)['dns']['delivered'],'Resuming after archival preserves the episode channel');
noticeDns($f,$resume+1800,'platform');noticeDns($f,$resume+2700,'platform');$planner->plan('watch',$id,$uid,$resume+2702);
$restored=WatchNotice::where('watch_id',$id)->where('kind','ns_restored')->sole();
same(['app'],$restored->data['channels'],'Recovery targets only the previously accepted channel');
$recovery=$delivery->reserve($restored->id,'app',$resume+2703);
expect($recovery!==null&&$delivery->handoff($recovery,$resume+2703),'Recovery still reaches the inbox after archival');
same(null,$delivery->reserve($restored->id,'mail',$resume+2703),'Recovery cannot add an undelivered channel');
// Cold payload expiry cannot remove replay protection.
HistoryRetention::tick(Carbon\CarbonImmutable::createFromTimestampUTC($now)->addYears(4)->getTimestamp());
expect(HistoryRetention::replayed('notice',$notice->notice_key)&&HistoryRetention::replayed('delivery',$sent->delivery_key),'Replay protection outlives cold history');
echo "Closed notices and deliveries archive, uncertain sends stay active, and expired history cannot replay.\n";
$nativeSuccessful=true;
