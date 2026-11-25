<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/dns-template.php';
require_once __DIR__.'/Support/fixture-cases.php';
use App\Models\User;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\DomainAudit;
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,AuditEvents,EnomTestSendLimitSchema,NativeUserDeletion,RetentionSchema,StaffSettingsReview,UserDeletionSchema};
use Paymenter\Extensions\Servers\Domains\Holding\Retention\HistoryRetention;
// Every case starts from the same installed extension, owner, other customer, server and DNS profile. Each case adds
// its own history, money or deletion rows; the deletion cases boot the native user deletion hooks themselves.
$nativeSuccessful=false;
extract(dnsFixture());
// The DNS profile audit is marked with the real clock. Fix that mark so the far-future sweeps copy a fixed epoch.
DB::table(RetentionSchema::MARKS)->where('family','domain_audit')->update(['recorded_at'=>strtotime('2026-10-18 12:00:00 UTC')]);
// Pin each case to the prepared Carbon clocks explicitly, whatever a reset restores.
$pinned=[Carbon\Carbon::getTestNow(),Carbon\CarbonImmutable::getTestNow()];
$pin=static function()use($pinned):void{[$native,$immutable]=$pinned;Carbon\Carbon::setTestNow($native);Carbon\CarbonImmutable::setTestNow($immutable);};
$fixed=compact('entry','owner','other','server','profile','pin');

// Encrypted event retention keeps young and unknown entries, moves atomically and keeps replay identities.
$cases['active-audits']=static function()use($fixed):void {extract($fixed);
    $pin();
$now=1800000000;$at=Carbon\CarbonImmutable::createFromTimestampUTC($now)->addMonthsNoOverflow(13)->getTimestamp()+1;
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
same(strtotime('2025-02-28 UTC'),HistoryRetention::cutoff(strtotime('2026-03-31 UTC'),13),'Month-end cutoff does not overflow');
HistoryRetention::tick((int)$cold->delete_after);
expect(DB::table(RetentionSchema::COLD)->where('id',$cold->id)->exists(),'Three-year boundary is retained');
HistoryRetention::tick((int)$cold->delete_after+1);
expect(!DB::table(RetentionSchema::COLD)->where('id',$cold->id)->exists(),'Cold event payload expires after three years');
DB::transaction(fn()=>AuditEvents::append($server->id,'staff_settings',$old,$key));
expect(!DB::table(AuditArchives::TABLE)->where('event_key',$key)->exists(),'Original identity cannot replay after cold expiry');
try{DB::transaction(fn()=>AuditEvents::append($server->id,'staff_settings',[...$old,'key'=>'changed'],$key));expect(false,'Changed event identity accepted');}
catch(DomainException $e){same('Audit event identity changed.',$e->getMessage(),'Changed event identity still refuses after cold expiry');}
};

// Audit retention uses proven epochs, keeps ambiguous times and lists unreadable events.
$cases['audit']=static function()use($fixed):void {extract($fixed);
    $pin();
$now=1800000000;
$a=DomainAudit::create(['subject_type'=>'dns_profile','subject_id'=>$profile->id,'user_id'=>$owner->id,'event'=>'dns_profile_saved','after_revision'=>'1','data'=>[],'created_at'=>'2020-01-01 00:00:00']);
$legacy=DomainAudit::create(['subject_type'=>'dns_profile','subject_id'=>$profile->id,'user_id'=>$owner->id,'event'=>'dns_profile_saved','after_revision'=>'1','data'=>[],'created_at'=>'2020-01-01 00:00:00']);
if(is_file(dirname(__DIR__).'/Domains/Services/RetentionSchema.php')){
    DB::table(RetentionSchema::MARKS)->where('family','domain_audit')->where('source_id',$a->id)->update(['recorded_at'=>$now]);
    DB::table(RetentionSchema::MARKS)->where('family','domain_audit')->where('source_id',$legacy->id)->delete();
    DB::table('domains_audit')->where('id',$legacy->id)->update(['time_zone'=>null]);
}
$entry=['key'=>'synthetic','phase'=>'completed','action'=>'catalogue_feed','at'=>gmdate(DATE_ATOM,$now)];
$key=hash('sha256','synthetic-catalogue-retention');
DB::transaction(fn()=>AuditEvents::append($server->id,'catalogue_sync',$entry,$key));
$event=DB::table(AuditArchives::TABLE)->where('event_key',$key)->value('id');
// UTC rows written before retention have a proven instant without a recording mark.
$utc=DomainAudit::create(['subject_type'=>'dns_profile','subject_id'=>$profile->id,'user_id'=>$owner->id,'event'=>'dns_profile_saved','after_revision'=>'1','data'=>[],'created_at'=>gmdate('Y-m-d H:i:s',$now)]);
DB::table(RetentionSchema::MARKS)->where('family','domain_audit')->where('source_id',$utc->id)->delete();
$at=Carbon\CarbonImmutable::createFromTimestampUTC($now)->addMonthsNoOverflow(13)->getTimestamp()+1;
HistoryRetention::tick($at);
expect(!DomainAudit::whereKey($a->id)->exists(),'Marked ordinary audit leaves active history after thirteen months');
expect(DomainAudit::whereKey($legacy->id)->exists(),'Unmarked mixed-zone audit stays active');
same('unmarked_audit_time',DB::table(RetentionSchema::MARKS)->where('family','domain_audit')->where('source_id',$legacy->id)->value('reason'),'Ambiguous audit is listed explicitly');
expect(!DB::table('domains_audit_archives')->where('id',$event)->exists(),'Explicitly dated completed audit event moves to cold storage');
expect(!DomainAudit::whereKey($utc->id)->exists(),'Earlier explicitly UTC audit moves without guessing a site zone');
$bad=DB::table('domains_audit_archives')->insertGetId(['server_id'=>$server->id,'family'=>'catalogue_sync','event_key'=>hash('sha256','synthetic-bad-event'),'payload'=>'damaged encrypted event']);
$badDate=DB::table('domains_audit_archives')->insertGetId(['server_id'=>$server->id,'family'=>'catalogue_sync','event_key'=>hash('sha256','synthetic-bad-date'),'payload'=>Crypt::encryptString(json_encode(['key'=>'synthetic','phase'=>'completed','action'=>'catalogue_feed','at'=>'2020-02-31T00:00:00+00:00']))]);
HistoryRetention::tick($at);HistoryRetention::tick($at);
same('unknown_audit_time',DB::table(RetentionSchema::MARKS)->where('family','audit_events')->where('source_id',$badDate)->value('reason'),'Impossible dates are listed instead of normalized');
expect(DB::table('domains_audit_archives')->where('id',$bad)->exists(),'Unreadable event remains active');
same('unreadable_audit_event',DB::table(RetentionSchema::MARKS)->where('family','audit_events')->where('source_id',$bad)->value('reason'),'Unreadable event is listed without its contents');
};

// A later native deletion failure keeps the deletion fence and the retry finishes deletion.
$cases['deletion-fence']=static function()use($fixed):void {extract($fixed);
    $pin();
NativeUserDeletion::boot();
$fail=true;User::deleting(static function(User $u)use($owner,&$fail):void {if($fail&&$u->id===$owner->id){throw new RuntimeException('Synthetic late deletion failure');}});
try{$owner->fresh()->delete();expect(false,'Later native listener must fail');}catch(RuntimeException $e){same('Synthetic late deletion failure',$e->getMessage(),'Real native event failure');}
expect(User::whereKey($owner->id)->exists(),'Failed native deletion retains owner');
expect(DB::table(UserDeletionSchema::TABLE)->where('user_id',$owner->id)->exists(),'Deletion intent committed before later native failure');
$fail=false;$owner->fresh()->delete();expect(!User::whereKey($owner->id)->exists(),'Retry finishes native deletion');
};

// Customer deletion, contact removal, rollback and native invoice retention.
$cases['deletion']=static function()use($fixed):void {extract($fixed);
    $pin();
$uid=$owner->id;NativeUserDeletion::boot();
$owner->properties()->create(['key'=>'phone','name'=>'Phone','value'=>'+1.2025550100']);
$profileId=DB::table('domains_contact_profiles')->insertGetId(['user_id'=>$uid,'server_id'=>$server->id,'account_fingerprint'=>str_repeat('c',64),'profile_fingerprint'=>str_repeat('d',64),
    'details'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode(['contact'=>['email_address'=>'synthetic@example.com']]))]);
$other->properties()->create(['key'=>'phone','name'=>'Phone','value'=>'+1.2025550100']);
$deleting=$owner->fresh();$deleting->id=$other->id;$deleting->delete();
expect($other->properties()->where('key','phone')->exists(),'Changed in-memory key cannot erase another customer contact');
expect(DB::table(UserDeletionSchema::TABLE)->where('user_id',$uid)->exists(),'Deletion retains the fence for the erased customer');
expect(!DB::table(UserDeletionSchema::TABLE)->where('user_id',$other->id)->exists(),'Changed in-memory key cannot fence another customer');
DB::transaction(fn()=>NativeUserDeletion::requireOwner((int)$other->id));
expect(!DB::table('domains_contact_profiles')->where('id',$profileId)->exists(),'Customer deletion removes owned contact details');
expect(!DB::table('properties')->where('model_type',App\Models\User::class)->where('model_id',$uid)->where('key','phone')->exists(),'Customer deletion removes contact properties');
DB::beginTransaction();
$other->fresh()->delete();
DB::rollBack();
expect($other->properties()->where('key','phone')->exists(),'Rollback restores contact details');
// Core invoice deletion cascades cannot remove retained money.
$invoice=App\Models\Invoice::create(['user_id'=>$other->id,'currency_code'=>'USD','status'=>'paid','due_at'=>now()]);
try{$other->fresh()->delete();expect(false,'Financial owner deletion must refuse');}catch(DomainException $e){same(NativeUserDeletion::RETAINED,$e->getMessage(),'Financial evidence has the existing refusal');}
expect(App\Models\Invoice::whereKey($invoice->id)->exists(),'Invoice survives deletion attempt');
expect($other->properties()->where('key','phone')->exists(),'Refusal preserves contact details');
};

// Native invoice cascade is refused and money does not expire automatically.
$cases['money']=static function()use($fixed):void {extract($fixed);
    $pin();
NativeUserDeletion::boot();
$invoice=App\Models\Invoice::create(['user_id'=>$owner->id,'currency_code'=>'USD','status'=>'paid','due_at'=>now()]);
try{$owner->fresh()->delete();}catch(DomainException $e){same(NativeUserDeletion::RETAINED,$e->getMessage(),'Existing financial refusal');}
expect(App\Models\Invoice::whereKey($invoice->id)->exists(),'Customer deletion cannot cascade retained invoices');
expect(App\Models\User::whereKey($owner->id)->exists(),'Financial invoice still has its native owner');
$rows=DB::table('invoices')->get()->toJson();
HistoryRetention::tick(strtotime('2036-11-11 UTC'));
same($rows,DB::table('invoices')->get()->toJson(),'Even after seven years money is never automatically deleted');
};

// TEST send retention keeps the thirty-day boundary and the rolling count window.
$cases['sends']=static function()use($fixed):void {extract($fixed);
    $pin();
$now=1800000000;$account=str_repeat('a',64);
DB::table(EnomTestSendLimitSchema::ACCOUNTS)->insert(['account'=>$account]);
foreach([$now-30*86400-1,$now-30*86400,$now-86400,$now-1] as $at){DB::table(EnomTestSendLimitSchema::SENDS)->insert(['account'=>$account,'command'=>'GetDomains','sent_at'=>$at]);}
HistoryRetention::tick($now);
same(3,DB::table(EnomTestSendLimitSchema::SENDS)->count(),'TEST sends older than thirty days are deleted');
// The count window remains intact; a second turn at the same time is harmless.
HistoryRetention::tick($now);same(3,DB::table(EnomTestSendLimitSchema::SENDS)->count(),'Repeated sweep does not delete boundary or live sends');
same(2,DB::table(EnomTestSendLimitSchema::SENDS)->where('sent_at','>=',$now-86400)->count(),'Entire live rolling window survives');
};
nativeFixtureCases($cases,compact('entry','owner','other','server','profile'));
echo "Retention guards keep history, deletion fences, money and TEST send windows.\n";
$nativeSuccessful=true;
