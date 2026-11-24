<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/dns-template.php';
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\DomainAudit;
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,AuditEvents,HistoryRetention,RetentionSchema};
extract(dnsFixture());$now=1800000000;
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
if(is_file(dirname(__DIR__).'/Domains/Services/HistoryRetention.php')){HistoryRetention::tick($at);}
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
echo "Audit retention uses proven epochs, preserves ambiguous times, and lists unreadable events.\n";
$nativeSuccessful=true;
