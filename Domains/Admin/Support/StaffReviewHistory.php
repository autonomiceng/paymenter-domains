<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Plain text only. Policy is already loaded and its append-only history is capped at 100 entries. */
final class StaffReviewHistory
{
    public static function for(Operation $op, ?StaffGrants $grants = null, bool $detail = false): array
    {
        $grants ??= Staff::requireView();
        try { $history=$op->policy['staff_actions']??[]; }
        catch (\Throwable) { return ['Staff review history unavailable']; }
        if (!is_array($history)||!array_is_list($history)||count($history)>100) { return ['Staff review history unavailable']; }
        $limit=$detail?20:3; $lines=[];
        // Keep the latest entries in their recorded order, including both phases of a recheck.
        foreach (array_slice($history,-$limit) as $entry) {
            if (!is_array($entry)) { $lines[]='Staff review entry unavailable'; continue; }
            $action=match($entry['action']??null) {
                'hold'=>'Held', 'release'=>'Released',
                'recheck'=>match($entry['phase']??null) {
                    'read_requested'=>'Recheck requested', 'read_finished'=>'Recheck finished',
                    'read_failed'=>'Recheck failed', default=>'Recheck',
                },
                'resume_reads'=>'Reconciliation reads resumed', 'resume_observation'=>'Scheduled reads resumed',
                'disposition'=>'Terminal review recorded', 'refund'=>'Closure and credit reviewed',
                'credit'=>'Account credit retried', 'replacement'=>'Replacement renewal allowed',
                default=>'Staff review',
            };
            $date=null; $at=$entry['at']??null;
            if (is_string($at)) {
                try { $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$at); }
                catch (\ValueError) { $date=null; }
                if (!$date||$date->format(DATE_ATOM)!==$at) { $date=null; }
            }
            $parts=[$action,$date?$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC':'Date unavailable'];
            // Only stored display names are shown; actor_id is never resolved for display.
            if (is_string($entry['actor_name']??null)&&$entry['actor_name']!=='') { $parts[]='By '.$entry['actor_name']; }
            $reason=$entry['reason']??null;
            $parts[]=is_string($reason)&&$reason!=='' ? 'Reason: '.($detail?$reason:mb_strimwidth($reason,0,160,'…','UTF-8')) : 'Reason unavailable';
            $lines[]=implode(' · ',$parts);
        }
        $older=max(0,count($history)-$limit);
        if ($older) { $lines[]=$older.' older staff review '.($older===1?'entry':'entries').' not shown'.($detail?'':'; open request for details'); }
        return $lines;
    }
}
