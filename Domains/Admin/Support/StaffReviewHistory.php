<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
/** Plain text only. Policy is already loaded and its append-only history is capped at 100 entries. */
final class StaffReviewHistory
{
    /** History rows in recorded order: time ("M j, Y H:i UTC" or null for Missing), event, reason sub-line and who.
     * Null when the saved history can't be read. A check's request and result phases show as one row. */
    public static function rows(Operation $op, ?StaffGrants $grants = null): ?array
    {
        $grants ??= Staff::requireView();
        try { $history=$op->policy['staff_actions']??[]; } catch (\Throwable) { return null; }
        if (!is_array($history)||!array_is_list($history)||count($history)>100) { return null; }
        $done=array_flip(array_filter(array_map(fn($e)=>is_array($e)&&in_array($e['phase']??null,['read_finished','read_failed'],true)&&is_string($e['key']??null)?$e['key']:null,$history)));
        $rows=[];
        foreach ($history as $entry) {
            if (!is_array($entry)) { $rows[]=['time'=>null,'event'=>'Staff action','sub'=>null,'by'=>'Staff']; continue; }
            $action=$entry['action']??null; $phase=$entry['phase']??null;
            if ($phase==='read_requested'&&isset($done[$entry['key']??''])) { continue; }
            $contactUpdate=$action==='release'&&($entry['before']['reason']??null)==='contact_details_required';
            $event=match($action) {
                'hold'=>'Put on hold', 'release'=>$contactUpdate?'Contact details updated':'Hold released',
                'recheck'=>$phase==='read_failed'?'Status check failed':'Status checked',
                'resume_reads'=>'Status checked', 'resume_observation'=>'Sync resumed',
                'disposition'=>'Request closed', 'refund'=>'Cancelled',
                'credit'=>'Credit retried', 'replacement'=>'Renewal invoice reissued', 'engine_hold'=>'Back on hold',
                default=>'Staff action',
            };
            $date=null; $at=$entry['at']??null;
            if (is_string($at)) {
                try { $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$at); }
                catch (\ValueError) { $date=null; }
                if (!$date||$date->format(DATE_ATOM)!==$at) { $date=null; }
            }
            // Only stored display names are shown; actor_id is never resolved for display.
            $by=$action==='engine_hold' ? 'System' : (is_string($entry['actor_name']??null)&&$entry['actor_name']!=='' ? $entry['actor_name'] : 'Staff');
            // Actions without a reason field record their own name; that repeats the event, so it is not shown.
            $reason=$action==='engine_hold' ? Labels::reason($entry['reason']??null) : ($entry['reason']??null);
            $sub=is_string($reason)&&$reason!==''&&$reason!==($contactUpdate?'Use updated account details':(StaffOperationReview::ACTIONS[$action]??null)) ? $reason : null;
            $rows[]=['time'=>$date?$date->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i').' UTC':null,'event'=>$event,'sub'=>$sub,'by'=>$by];
        }
        return $rows;
    }
}
