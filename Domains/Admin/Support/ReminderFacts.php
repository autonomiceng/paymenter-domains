<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules;
final class ReminderFacts
{
    /** "Reminder emails" row for the account page: On or Off, with the days below. */
    public static function emails(int $server):array
    {
        $r=Schema::hasTable('domains_reminder_schedules')?DB::table('domains_reminder_schedules')->where('server_id',$server)->first():null;
        $binding=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server)->value('account_fingerprint');
        $days=static function(?string $v,array $default,bool $before):?array {
            if($v===null){return $default;}
            $values=ReminderSchedules::storedDays($v);if($values===null){return null;}
            if($before){rsort($values,SORT_NUMERIC);}else{sort($values,SORT_NUMERIC);}
            return $values;
        };
        $list=static fn(array $v):string=>count($v)>1?implode(', ',array_slice($v,0,-1)).' and '.end($v):(string)($v[0]??'');
        $before=$days($r?->before_days,[60,30,7,1],true);$after=$days($r?->after_days,[3],false);
        if($before===null||$after===null){return ['label'=>'Reminder emails','value'=>Missing::LABEL,'tone'=>null,'cause'=>"Saved reminder days can't be read. Ask your site admin to restore them.",'action'=>null,'help'=>null];}
        $sub=implode(', ',array_filter([$before?$list($before).' '.(count($before)===1&&$before[0]===1?'day':'days').' before':null,$after?$list($after).' '.(count($after)===1&&$after[0]==1?'day':'days').' after':null]));
        $changed=$r&&$r->account_fingerprint!==$binding;
        return ['label'=>'Reminder emails','value'=>$r&&$r->enabled&&!$changed?'On':'Off','tone'=>$r&&$r->enabled&&!$changed?'success':null,
            'cause'=>$changed?'Paused: account changed':($sub!==''?$sub:null),'action'=>null,'help'=>null];
    }
    public static function schedule(int $server):array
    {
        Staff::requireView();if(!Schema::hasTable('domains_reminder_schedules')){return [];}
        $r=DB::table('domains_reminder_schedules')->where('server_id',$server)->first();
        $days=static function($v,bool $before=false):string {
            $values=ReminderSchedules::storedDays($v);if($values===null){return Missing::LABEL;}
            if($before){rsort($values,SORT_NUMERIC);}else{sort($values,SORT_NUMERIC);}
            return $values===[]?'None':implode(', ',$values);
        };
        $hold=Schema::hasTable('domains_observation_queues')?DB::table('domains_observation_queues')->where('server_id',$server)->value('catalogue_hold'):null;
        $binding=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server)->value('account_fingerprint');
        return ['Catalogue refresh'=>$hold?'On hold · inspect saved catalogue work before resuming':'No saved catalogue hold',
            'Customer reminders'=>$r?($r->account_fingerprint!==$binding?'On hold · account changed':($r->enabled?'On':'Off')):'Not configured',
            'Days before expiry'=>$r?$days($r->before_days,true):'60, 30, 7, 1','Days after expiry'=>$r?$days($r->after_days):'3',
            'Notification safety'=>'Unknown expiry or price alerts staff. Queued mail is not delivery confirmation.'];
    }
}
