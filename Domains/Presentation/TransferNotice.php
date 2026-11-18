<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\TransferPolling;
/** Customer-safe reason and next step, shared with staff. Staff controls are added by the staff projection. */
final class TransferNotice
{
    public static function title(?string $reason): string
    {
        return match($reason){
            'transfer_pending','transfer_read_deferred'=>'Transfer pending.',
            'transfer_needs_action'=>'Old registrar needs an update.',
            'transfer_deadline_passed'=>'Transfer is taking too long.',
            'transfer_authority_changed'=>'Transfer details changed.',
            'transfer_charge_unexpected'=>'Transfer payment needs a check.',
            'transfer_status_unavailable'=>'Transfer status could not be checked.',
            'transfer_completion_unconfirmed'=>'Transfer completion needs a check.',
            default=>'Transfer needs a check.'};
    }
    private static function deadline(Operation $op, int $now): array
    {
        $title = self::title('transfer_deadline_passed');
        return TransferPolling::followupScheduled($op, $now)
            ? ['title'=>$title,'body'=>'We’re still checking the original transfer with the registrar.','tone'=>'warning','contact'=>false]
            : ['title'=>$title,'body'=>'Contact support. We’ll check the transfer status.','tone'=>'warning'];
    }

    public static function for(Operation $op,int $now): ?array
    {
        if(!TransferPolling::applies($op)){return null;}
        $r=$op->policy['reconciliation']??[];$reason=$r['reason']??null;
        if($reason==='transfer_rejection_unreconciled'){
            return ['title'=>'Registrar rejected the transfer.','body'=>'Final result and fee need a check. Contact support. Don’t start another transfer.','tone'=>'warning'];
        }
        $retryingUnavailable = $reason === 'transfer_status_unavailable' && TransferPolling::followupScheduled($op, $now);
        if($reason==='transfer_deadline_passed'||$retryingUnavailable){
            if (($r['transfer_status'] ?? null) === 'needs_action') {
                $privacy = ($r['transfer_privacy_blocked'] ?? false) === true ? ' Turn off WHOIS privacy there.' : '';
                return ['title'=>self::title('transfer_deadline_passed'),'body'=>'Check approval, the domain lock and auth code at your old registrar.'.$privacy,
                    'tone'=>'warning','contact'=>false];
            }
            return self::deadline($op, $now);
        }
        if(TransferPolling::expected($op)&&($r['transfer_status']??null)==='needs_action'){
            $privacy=($r['transfer_privacy_blocked']??false)===true?' Turn off WHOIS privacy there.':'';
            return ['title'=>self::title('transfer_needs_action'),'body'=>'Check approval, the domain lock and auth code at your old registrar.'.$privacy.' We keep checking.','tone'=>'warning'];
        }
        if(($r['staff_alert']??false)===true&&!TransferPolling::expected($op)){
            return ['title'=>self::title($reason),'body'=>'Contact support to check this transfer. Don’t start another transfer.','tone'=>'warning'];
        }
        if(TransferPolling::expired($op,$now)&&($r['final_check']??null)!=='done'){
            return self::deadline($op, $now);
        }
        if($reason==='transfer_read_deferred'||TransferPolling::expected($op)){
            return ['title'=>self::title('transfer_pending'),'body'=>'We keep checking. There’s nothing you need to do.','tone'=>'info'];
        }
        return null;
    }
}
