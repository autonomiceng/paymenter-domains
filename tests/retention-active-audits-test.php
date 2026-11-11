<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,AuditEvents,HistoryRetention,RetentionSchema,StaffSettingsReview};
extract(dnsFixture());$now=1800000000;$at=Carbon\CarbonImmutable::createFromTimestampUTC($now)->addMonthsNoOverflow(13)->getTimestamp()+1;
$old=['key'=>'old','phase'=>'completed','action'=>StaffSettingsReview::ACTIONS['price_freshness'],'at'=>gmdate(DATE_ATOM,$now)];
$young=[...$old,'key'=>'young','at'=>gmdate(DATE_ATOM,$at-1)];$unknown=[...$old,'key'=>'unknown','action'=>'Unknown retained event'];
$entry=['actor_id'=>$owner->id,'at'=>gmdate(DATE_ATOM,$now),'revision'=>2,'reason'=>'Synthetic saved reminder policy','before'=>['enabled'=>false],'after'=>['enabled'=>true]];
$key=hash('sha256','synthetic-retained-settings');
DB::transaction(static function()use($server,$old,$young,$unknown,$entry,$key):void {
    AuditEvents::append($server->id,'staff_settings',$old,$key);
    AuditEvents::append($server->id,'staff_settings',$young);
    AuditEvents::append($server->id,'staff_settings',$unknown);
    AuditEvents::append($server->id,'reminders',$entry);
});
$unknownId=DB::table(AuditArchives::TABLE)->where('server_id',$server->id)->where('family','staff_settings')->max('id');
$oldId=DB::table(AuditArchives::TABLE)->where('event_key',$key)->value('id');
HistoryRetention::tick($at);
same(['young','unknown'],array_column(AuditEvents::recent($server->id,'staff_settings'),'key'),'Expired completed events leave current event history');
same([],AuditEvents::recent($server->id,'reminders'),'Expired reminder policy history leaves the active event rows');
same(2,DB::table(RetentionSchema::COLD)->where('family','audit_events')->count(),'Both event families move cold');
$cold=DB::table(RetentionSchema::COLD)->where('family','audit_events')->where('source_id',$oldId)->sole();
$copy=json_decode(Crypt::decryptString($cold->payload),true,64,JSON_THROW_ON_ERROR)['row'];
same($key,$copy['event_key'],'Cold copy retains the original event identity');
same($old,json_decode(Crypt::decryptString($copy['payload']),true,32,JSON_THROW_ON_ERROR),'Cold copy retains the exact encrypted entry');
same('unclassified_audit_event',DB::table(RetentionSchema::MARKS)->where('family','audit_events')->where('source_id',$unknownId)->value('reason'),'Unclassified events stay active and listed');
// Source deletion, cold payload and replay identities commit together.
$retryKey=hash('sha256','synthetic-retry-settings');
DB::transaction(fn()=>AuditEvents::append($server->id,'staff_settings',$old,$retryKey));
$retryId=DB::table(AuditArchives::TABLE)->where('event_key',$retryKey)->value('id');
DB::table(RetentionSchema::SCANS)->where('family','audit_events')->update(['cursor'=>0]);
DB::unprepared("CREATE TRIGGER retention_event_fail BEFORE DELETE ON domains_audit_archives WHEN OLD.id = ".(int)$retryId." BEGIN SELECT RAISE(ABORT, 'synthetic event failure'); END");
try{HistoryRetention::tick($at);expect(false,'Event move must fail');}catch(Illuminate\Database\QueryException $e){expect(str_contains($e->getMessage(),'synthetic event failure'),'Expected event failure');}
DB::unprepared('DROP TRIGGER retention_event_fail');
expect(DB::table(AuditArchives::TABLE)->where('event_key',$retryKey)->exists(),'Failed move retains current event');
expect(!DB::table(RetentionSchema::COLD)->where('family','audit_events')->where('source_id',$retryId)->exists(),'Failed move leaves no cold duplicate');
expect(!HistoryRetention::replayed('audit_event',$retryKey),'Failed move leaves no replay marker');
HistoryRetention::tick($at);HistoryRetention::tick($at);
expect(!DB::table(AuditArchives::TABLE)->where('event_key',$retryKey)->exists(),'Cursor wrap retries the failed event');
HistoryRetention::tick((int)$cold->delete_after+1);
expect(!DB::table(RetentionSchema::COLD)->where('id',$cold->id)->exists(),'Cold event payload expires after three years');
DB::transaction(fn()=>AuditEvents::append($server->id,'staff_settings',$old,$key));
expect(!DB::table(AuditArchives::TABLE)->where('event_key',$key)->exists(),'Original identity cannot replay after cold expiry');
try{DB::transaction(fn()=>AuditEvents::append($server->id,'staff_settings',[...$old,'key'=>'changed'],$key));expect(false,'Changed event identity accepted');}
catch(DomainException $e){same('Audit event identity changed.',$e->getMessage(),'Changed event identity still refuses after cold expiry');}
echo "Encrypted event retention preserves young and unknown entries, atomic movement and permanent replay identities.\n";
$nativeSuccessful=true;
