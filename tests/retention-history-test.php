<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{WatchMaintenance,HistoryRetention,RetentionSchema};
$f=noticeFixture();extract($f,EXTR_SKIP);
noticeRegistry($f,$now);$first=(int)DB::table('domains_watch_registry')->max('id');
noticeRegistry($f,$now+100);$last=(int)DB::table('domains_watch_registry')->max('id');
(new WatchMaintenance())->tick($now+91*86400);
expect(DB::table('domains_watch_registry')->where('id',$first)->exists(),'Ordinary observations remain active for thirteen months');
HistoryRetention::tick($now+91*86400);
// Calendar months, including month ends; the boundary remains active.
same(strtotime('2025-02-28 UTC'),HistoryRetention::cutoff(strtotime('2026-03-31 UTC'),13),'Month-end cutoff does not overflow');
$coldAt=Carbon\CarbonImmutable::createFromTimestampUTC($now+101)->addMonthsNoOverflow(13)->getTimestamp();
HistoryRetention::tick($coldAt);HistoryRetention::tick($coldAt);expect(!DB::table('domains_watch_registry')->where('id',$first)->exists(),'Superseded observation moves to cold storage');
$cold=DB::table(RetentionSchema::COLD)->where('family','registry')->where('source_id',$first)->sole();
$payload=json_decode(Illuminate\Support\Facades\Crypt::decryptString($cold->payload),true,64,JSON_THROW_ON_ERROR);
same($first,$payload['row']['id'],'Cold storage preserves original identity');
expect(!str_contains($cold->payload,'rdap.example.com'),'Cold payload encrypted');
expect(DB::table('domains_watch_registry')->where('id',$last)->exists(),'Latest successful evidence remains active');
// Crash during insert rolls back keys, payload and active-row removal together.
$coldAt+=1000;
$copy=(array)DB::table('domains_watch_registry')->where('id',$last)->sole();unset($copy['id']);$copy['completed_at']=$now+200;
$newer=DB::table('domains_watch_registry')->insertGetId($copy);
DB::unprepared("CREATE TRIGGER retention_fail BEFORE DELETE ON domains_watch_registry BEGIN SELECT RAISE(ABORT, 'synthetic move failure'); END");
DB::table(RetentionSchema::SCANS)->where('family','registry')->update(['cursor'=>0]);
try{HistoryRetention::sweep('registry',$coldAt);expect(false,'Move must fail');}catch(Illuminate\Database\QueryException $e){expect(str_contains($e->getMessage(),'synthetic move failure'),'Expected injected database failure');}
DB::unprepared('DROP TRIGGER retention_fail');
expect(DB::table('domains_watch_registry')->where('id',$last)->exists(),'Failed move leaves active row');
expect(!DB::table(RetentionSchema::COLD)->where('family','registry')->where('source_id',$last)->exists(),'Failed move leaves no cold duplicate');
// A crashed cursor claim wraps, then retries instead of losing a row permanently.
HistoryRetention::sweep('registry',$coldAt);HistoryRetention::sweep('registry',$coldAt);
expect(!DB::table('domains_watch_registry')->where('id',$last)->exists(),'Retry moves the preserved row');
HistoryRetention::tick((int)$cold->delete_after);expect(DB::table(RetentionSchema::COLD)->where('id',$cold->id)->exists(),'Three-year boundary is retained');
HistoryRetention::tick((int)$cold->delete_after+1);expect(!DB::table(RetentionSchema::COLD)->where('id',$cold->id)->exists(),'Cold history deleted after three years');
// Bounded traversal continues past permanently retained evidence.
DB::table('domains_public_evidence_refs')->insert(['family'=>'registry','observation_id'=>$newer]);
DB::table(RetentionSchema::SCANS)->where('family','registry')->update(['cursor'=>0]);
$copy['completed_at']=$now+300;$copy['result']='unknown';$copy['reason']='endpoint_failed';
$unknown=DB::table('domains_watch_registry')->insertGetId($copy);$copy['completed_at']=$now+400;$copy['result']='facts';$copy['reason']='facts';DB::table('domains_watch_registry')->insert($copy);
HistoryRetention::sweep('registry',$coldAt,1);expect(DB::table('domains_watch_registry')->where('id',$unknown)->exists(),'One-row budget cannot process next row');
HistoryRetention::sweep('registry',$coldAt,1);expect(!DB::table('domains_watch_registry')->where('id',$unknown)->exists(),'Next cursor turn progresses beyond pinned evidence');
$copy['user_id']=987654;$orphan=DB::table('domains_watch_registry')->insertGetId($copy);DB::table('domains_watch_registry')->insert($copy);
DB::table(RetentionSchema::SCANS)->where('family','registry')->update(['cursor'=>0]);HistoryRetention::sweep('registry',$coldAt);
expect(DB::table('domains_watch_registry')->where('id',$orphan)->exists(),'Orphaned evidence stays active');
same('missing_owner',DB::table(RetentionSchema::MARKS)->where('family','registry')->where('source_id',$orphan)->value('reason'),'Missing ownership is listed without guessing');
echo "Observation retention, calendar boundaries, encryption, bounded progress and atomic crash recovery passed.\n";

$nativeSuccessful=true;
