<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryRetention,RetentionSchema,WatchNotices};
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
// Cold payload expiry cannot remove replay protection.
HistoryRetention::tick(Carbon\CarbonImmutable::createFromTimestampUTC($now)->addYears(4)->getTimestamp());
expect(HistoryRetention::replayed('notice',$notice->notice_key)&&HistoryRetention::replayed('delivery',$sent->delivery_key),'Replay protection outlives cold history');
echo "Closed notices and deliveries archive, uncertain sends stay active, and expired history cannot replay.\n";
$nativeSuccessful=true;
