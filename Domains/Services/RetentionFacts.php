<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{Crypt,DB};
/** An epoch means closed ordinary history; null means current/open; a reason means unclassifiable. */
final class RetentionFacts
{
    public static function at(string $family,object $r): int|string|null
    {
        if($family==='test_sends'){return self::epoch($r->sent_at);}
        if($family==='notices'){
            if($r->state==='open'){return null;}
            if(!in_array($r->state,['resolved','suppressed'],true)){return 'unknown_notice_state';}
            if(DB::table('domains_watch_deliveries')->where('notice_id',$r->id)->exists()){return null;}
            return self::epoch($r->resolved_at);
        }
        if($family==='deliveries'){
            $notice=DB::table('domains_watch_notices')->find($r->notice_id);
            if(!$notice){return 'missing_notice';}
            if($notice->state==='open'||in_array($r->state,['reserved','uncertain','render_failed'],true)){return null;}
            if(!in_array($notice->state,['resolved','suppressed'],true)||!in_array($r->state,['accepted','suppressed'],true)){return 'unknown_delivery_state';}
            $at=self::epoch($r->state==='accepted'?$r->accepted_at:$r->reserved_at);$closed=self::epoch($notice->resolved_at);
            return is_int($at)&&is_int($closed)?max($at,$closed):'unknown_delivery_time';
        }
        if(in_array($family,['registry','watch_dns','managed_dns'],true)){
            if(DB::table('domains_public_evidence_refs')->where('family',$family)->where('observation_id',$r->id)->exists()
                ||DB::table('domains_watch_notices')->where('family',$family)->where('observation_id',$r->id)->exists()){return null;}
            $table=HistoryRetention::TABLES[$family];$target=$family==='managed_dns'?'domain_id':'watch_id';
            $newer=static fn()=>DB::table($table)->where($target,$r->$target)->where('user_id',$r->user_id)->where('id','>',$r->id);
            if($family==='registry'){
                if(!in_array($r->result,['facts','unknown','no_registration'],true)){return 'unknown_observation_result';}
                if(!$newer()->exists()||$r->result==='facts'&&!$newer()->where('result','facts')->exists()){return null;}
            }else{
                if(!in_array($r->result,['complete','partial','unknown'],true)){return 'unknown_observation_result';}
                if(!$newer()->where('check_kind',$r->check_kind)->exists()){return null;}
                foreach(['dns','website','email'] as $axis){
                    if($r->$axis!==null&&!$newer()->where('check_kind',$r->check_kind)->whereNotNull($axis)->exists()){return null;}
                }
            }
            return self::epoch($r->completed_at);
        }
        if($family==='reminders'){
            if(in_array($r->state,['handoff','uncertain'],true)){return null;}
            if(!in_array($r->state,['accepted','suppressed'],true)){return 'unknown_reminder_state';}
            return self::epoch($r->state==='accepted'?$r->accepted_at:$r->created_at);
        }
        if($family==='watch_audit'){
            // Old wall times have mixed provenance (DM-04). Never infer their zone from today's site setting.
            $at=DB::table(RetentionSchema::MARKS)->where('family',$family)->where('source_id',$r->id)->value('recorded_at');
            if($at===null&&($r->time_zone??null)==='UTC'){
                try{$at=\Carbon\CarbonImmutable::parse($r->created_at,'UTC')->getTimestamp();}catch(\Throwable){return 'unknown_audit_time';}
            }
            if($at===null){return 'unmarked_audit_time';}
            $events=['watch'=>['discovery_added','discovery_returned','discovery_suffix_changed','discovery_retired','discovery_paused','discovery_active','registration_managed',
                'staff_pause','staff_resume','staff_profile','staff_read_requested','staff_note'],
                'source'=>['source_retired'],'product_map'=>['mapping_enabled','mapping_found','mapping_saved'],
                'dns_profile'=>['dns_profile_saved','dns_profile_deleted','deployment_dns_default'],'site_dns'=>['site_dns_default_changed'],
                'managed_dns'=>['dns_template_assigned'],'psl'=>['psl_rebaseline'], 'notice_delivery'=>['notice_resend','notice_claim_relinked']];
            if(!in_array($r->event,$events[$r->subject_type]??[],true)){return 'unclassified_audit_event';}
            if($r->subject_type==='notice_delivery'&&DB::table('domains_watch_deliveries')->where('id',$r->subject_id)->exists()){return null;}
            // These rows are still authority for discovery or adoption, even when their display age is old.
            if($r->subject_type==='source'&&$r->event==='source_retired'){return null;}
            if($r->subject_type==='watch'){
                foreach([['staff_pause','staff_resume'],['discovery_paused'],['discovery_retired','discovery_active','staff_retire','staff_stop']] as $group){
                    if(in_array($r->event,$group,true)&&!DB::table('domains_watch_audit')->where('subject_type','watch')->where('subject_id',$r->subject_id)->whereIn('event',$group)->where('id','>',$r->id)->exists()){return null;}
                }
            }
            // Do not retire history for a watch with open notices or unresolved public work.
            if($r->subject_type==='watch'&&(DB::table('domains_watch_notices')->where('watch_id',$r->subject_id)->where('state','open')->exists()
                ||DB::table('domains_watch_work')->where('watch_id',$r->subject_id)->where(fn($q)=>$q->whereNotNull('claim')->orWhere('attempts','>',0))->exists())){return null;}
            return self::epoch($at);
        }
        if($family==='audit_events'){
            if(!is_string($r->event_key)||!preg_match('/^[a-f0-9]{64}$/D',$r->event_key)){return 'unclassified_audit_event';}
            try{$entry=json_decode(Crypt::decryptString($r->payload),true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return 'unreadable_audit_event';}
            return self::eventAt($r->family,$entry);
        }
        return 'unclassified_family';
    }
    public static function eventAt(string $family,mixed $row): int|string
    {
        if(!in_array($family,['reminders','catalogue_sync','staff_settings'],true)||!is_array($row)||!$row){return 'unclassified_audit_event';}
        if(!is_array($row)||!is_string($row['at']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$row['at'])){return 'unmarked_audit_time';}
        if($family==='reminders'&&(!is_int($row['revision']??null)||$row['revision']<1)){return 'unclassified_audit_event';}
        if($family!=='reminders'){
            if(($row['phase']??null)!=='completed'){return 'unresolved_audit_event';}
            $actions=$family==='catalogue_sync'?['catalogue_feed','catalogue_facts','catalogue_republish','catalogue_sync']:array_values(array_diff_key(StaffSettingsReview::ACTIONS,array_flip(['history','delivery_review','alert_review'])));
            if(!in_array($row['action']??null,$actions,true)||isset($row['input']['operation_id'])){return 'unclassified_audit_event';}
        }
        try{
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$row['at']);$errors=\DateTimeImmutable::getLastErrors();
            if(!$date||$errors!==false||!in_array($row['at'],[$date->format(DATE_ATOM),str_replace('+00:00','Z',$date->format(DATE_ATOM))],true)){return 'unknown_audit_time';}
            $stamp=$date->getTimestamp();
        }catch(\Throwable){return 'unknown_audit_time';}
        return $stamp>0?$stamp:'unknown_audit_time';
    }
    private static function epoch(mixed $value): int|string
    {return (is_int($value)||is_string($value)&&ctype_digit($value))&&(int)$value>0?(int)$value:'unknown_time';}
}
