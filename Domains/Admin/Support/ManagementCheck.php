<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\{RequestStatus,StaffTime};
use Paymenter\Extensions\Servers\Domains\Services\ManagementReadRecovery;
/** The latest registrar read of a DNS, nameserver or contact change, in staff words. The toast, notice and History share it. */
final class ManagementCheck
{
    public const CONFIRMED='Registrar checked';
    public const UNCONFIRMED='Still unconfirmed';
    /** A status read can't recover an email acceptance the send's own reply never saved. */
    public const EMAIL="The registrar's reply to the email request was not saved, so a status check can't show whether the email was sent. Ask the customer whether the auth code email arrived.";
    /** @return ?array{title:string,at:string,detail:string,body:string,confirmed:bool} Null for other requests or before any read. */
    public static function words(Operation $op): ?array
    {
        try{
            if(!isset($op->policy['management'])){return null;}
            $read=ManagementReadRecovery::latest($op);
            if(!is_string($read['at']??null)){return null;}
            $at='Checked '.StaffTime::at((new \DateTimeImmutable($read['at']))->getTimestamp()).'.';
        }catch(\Throwable){return null;}
        $email=$op->action==='auth_code_email';
        $confirmed=$op->state==='succeeded';$failed=$op->state==='rejected';$lost=$email&&($op->attempt['management_ack']['email_accepted']??null)!==true;
        $detail=match(true){$confirmed=>$email?'The registrar accepted the auth code email request.':'The change is confirmed.',$failed=>Labels::operationReason($op)??RequestStatus::REFUSED.' The customer can try again.',$lost=>self::EMAIL,
            default=>'The registrar does not show this change yet. Automatic checks continue.'};
        return ['title'=>$confirmed?self::CONFIRMED:($failed?RequestStatus::SET['failed'][0]:self::UNCONFIRMED),'at'=>$at,'detail'=>$detail,'body'=>$at.' '.$detail,'confirmed'=>$confirmed];
    }
}
