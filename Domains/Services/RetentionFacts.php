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
            $events=['dns_profile'=>['dns_profile_saved','dns_profile_deleted','deployment_dns_default'],'site_dns'=>['site_dns_default_changed']];
            if(!in_array($r->event,$events[$r->subject_type]??[],true)){return 'unclassified_audit_event';}
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
