<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules;
final class ReminderFacts
{
    private const HELP='Checks for expiry reminders on the days shown. Renewal state can skip a day. The 30-day reminder also needs Renewals on.';
    /** "Reminder emails" row for the account page: On or Off. The days are on the Reminder schedule row. */
    public static function emails(int $server):array
    {
        $r=self::saved($server);
        $binding=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server)->value('account_fingerprint');
        if(self::days($r)===null){return ['label'=>'Reminder emails','value'=>Missing::LABEL,'tone'=>null,'cause'=>"Saved reminder days can't be read. Restore them from a backup.",'action'=>null,'help'=>self::HELP,'edit'=>true,'buttons'=>[ReminderAction::make($server)]];}
        $changed=$r&&$r->account_fingerprint!==$binding;
        $on=$r&&$r->enabled&&!$changed;
        $mailOff=config('settings.mail_disable')||trim((string)config('mail.from.address'))==='';
        $cause=match(true){$changed=>'Paused: account changed. Restore the saved account identity first.',
            $r===null=>'Reminder schedule is missing. Restart the queue worker, then reload this page.',
            !$on=>'Reminder emails are paused until you turn them on. Turn on to email customers on these days.',default=>null};
        if($mailOff){$cause=trim(($cause??'').' Mail is not set up. Set up and enable email in Settings → Mail.');}
                return ['label'=>'Reminder emails','value'=>$on?'On':'Off','tone'=>$changed||$mailOff?'warning':($on?'success':null),
            'cause'=>$cause!==''?$cause:null,'action'=>null,'help'=>self::HELP,'buttons'=>$changed?[]:[ReminderAction::toggle($server)]];
    }

    /** Null when the saved days can't be read: Reminder emails says so. */
    public static function scheduleRow(int $server):?array
    {
        $days=self::days(self::saved($server));if($days===null){return null;}
        [$before,$after]=$days;
        $list=static fn(array $v):string=>count($v)>1?implode(', ',array_slice($v,0,-1)).' and '.end($v):(string)($v[0]??'');
        $text=implode(', ',array_filter([$before?$list($before).' '.(count($before)===1&&$before[0]===1?'day':'days').' before':null,$after?$list($after).' '.(count($after)===1&&$after[0]==1?'day':'days').' after':null]));
        return ['label'=>'Reminder schedule','value'=>$text!==''?$text:'No days set','tone'=>null,'cause'=>null,'action'=>null,'help'=>null,'edit'=>true,'buttons'=>[ReminderAction::make($server)]];
    }
    private static function saved(int $server):?object
    { return Schema::hasTable('domains_reminder_schedules')?DB::table('domains_reminder_schedules')->where('server_id',$server)->first():null; }
    /** @return ?array{0:list<int>,1:list<int>} Days before and after expiry, newest first. */
    private static function days(?object $r):?array
    {
        $days=static function(?string $v,array $default,bool $before):?array {
            if($v===null){return $default;}
            $values=ReminderSchedules::storedDays($v);if($values===null){return null;}
            if($before){rsort($values,SORT_NUMERIC);}else{sort($values,SORT_NUMERIC);}
            return $values;
        };
        $before=$days($r?->before_days,[60,30,7,1],true);$after=$days($r?->after_days,[3],false);
        return $before===null||$after===null?null:[$before,$after];
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
