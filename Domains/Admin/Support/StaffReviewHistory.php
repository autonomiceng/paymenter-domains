<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
/** Plain text only. Policy is already loaded and its append-only history is capped at 100 entries. */
final class StaffReviewHistory
{
    public const SENT = 'Sent to registrar';
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
                'recheck'=>$phase==='read_failed'?'Status check failed':($phase==='read_requested'?'Status check requested':(isset($entry['check'])?\Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::words($entry['check'])['title']
                    :(isset($entry['management_check'])?match($entry['management_check']['outcome']??(($entry['management_check']['confirmed']??false)===true?'confirmed':(($entry['after']['state']??null)==='rejected'?'failed':'unconfirmed'))){'confirmed'=>'Status check: change confirmed','failed'=>'Status check: failed',default=>'Status check: still unconfirmed'}:'Status check finished'))),
                'resume_reads'=>'Status checks restarted', 'resume_observation'=>'Sync resumed',
                'approve_charge'=>'Captured charge approved','disposition'=>'Request closed', 'refund'=>'Cancelled',
                'credit'=>$op->funding_basis==='complimentary'?'Order closed':'Refund checked', 'replacement'=>'Renewal invoice reissued', 'engine_hold'=>'Back on hold',
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
            $reason=$action==='engine_hold' ? Labels::reason($entry['reason']??null,$op->funding_basis==='complimentary') : ($entry['reason']??null);
            $sub=is_string($reason)&&$reason!==''&&$reason!==($contactUpdate?'Use updated account details':(StaffOperationReview::ACTIONS[$action]??null)) ? $reason : null;
            $rows[]=['time'=>self::time($date?:null),'event'=>$event,'sub'=>$sub,'by'=>$by];
        }
        return $rows;
    }
    /** The whole story: created, sent and finished, in time order with the rows() above. A row with no time stays after the one before it. */
    public static function timeline(Operation $op, array $rows): array
    {
        $system=fn(?\DateTimeInterface $at,string $event,?string $sub=null)=>['time'=>self::time($at),'event'=>$event,'sub'=>$sub,'by'=>'System'];
        $status=Labels::requestStatus($op);
        try { $hold=$op->policy['first_hold']??null; } catch (\Throwable) { $hold=null; }
        // A staff Cancelled already says how this request ended.
        $ended=$op->completed_at&&in_array($op->state,['succeeded','rejected','disposed','cancelled'],true)&&!in_array($status['label'],array_column($rows,'event'),true);
        $all=[$system($op->created_at,'Request created'),...($hold?[$system(new \DateTimeImmutable($hold['at']),'Put on hold',Labels::reason($hold['reason'],$op->funding_basis==='complimentary'))]:[]),...($op->started_at&&($sent=self::sendEvent($op))?[$system($op->started_at,$sent)]:[]),...$rows,...($ended?[$system($op->completed_at,$status['label'],$status['reason'])]:[])];
        $at=PHP_INT_MIN;
        $keyed=array_map(function(array $row)use(&$at){$at=$row['time']===null?$at:(int)strtotime($row['time']);return [$at,$row];},$all);
        usort($keyed,fn(array $a,array $b)=>$a[0]<=>$b[0]);
        return array_column($keyed,1);
    }
    /** What the saved attempt proves about the send, or null when it never left here. A claim saves started_at before the seal, so that alone proves nothing.
     * A result recorded after the seal outranks the possibly_sent written at it. */
    public static function sendEvent(Operation $op): ?string
    {
        try { $a=$op->attempt??[]; } catch (\Throwable) { $a=[]; }
        $send=$a['transfer_terminal']['send_state']??$a['execution']['send_state']??$a['send_state']??null;
        if($send==='definitely_unsent'){return null;}
        return $send==='sent'||in_array($op->state,['pending','succeeded'],true)?self::SENT:RequestStatus::STATES['uncertain'][1];
    }
    public static function time(?\DateTimeInterface $at): ?string
    { return $at?\DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i').' UTC':null; }
}
