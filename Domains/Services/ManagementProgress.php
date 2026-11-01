<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Stored state only. Shared by both controls and the owner status endpoint. */
final class ManagementProgress
{
    public const UNREADABLE='This change cannot be read. Contact support.';
    public static function setting(string $action): string
    { return match($action) { 'contact_profile'=>'contacts','srv'=>'dns',default=>$action }; }
    public static function pending(Operation $op, Domain $d): bool
    { return !isset($op->attempt['management_terminal']) && (!ManagementEvidence::resolved($op,$d)
        || in_array($op->state,['queued','awaiting_payment','running','pending','uncertain'],true) || ManagementEvidence::recoverable($op)); }
    public static function for(Domain $d): array
    {
        $rows=[];$priorities=[];
        $scope=$d->operations()->whereIn('action',['lock','nameservers','contacts','contact_profile','dns','srv','auth_code_email'])
            ->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint);
        $latest=(clone $scope)->selectRaw('MAX(id)')->groupBy('action');
        $retained=(clone $scope)->where(fn($q)=>$q->whereNull('safe_code')->orWhere('safe_code','!=','management_retired'))->selectRaw('MAX(id)')->groupBy('action');
        // Prefer retained work, pending then finished; stream bounded pages so newer holds cannot hide older work.
        $candidates=(clone $scope)->where(fn($q)=>$q->whereIn('id',$latest)->orWhereIn('id',$retained)->orWhereIn('state',['queued','awaiting_payment','running','pending','uncertain','held']))
            ->lazyByIdDesc(100);
        foreach($candidates as $op){
            $key=self::setting($op->action);
            try {
                if(!isset($op->policy['management']) || !in_array($op->action,['lock','nameservers','contacts','contact_profile','dns','srv','auth_code_email'],true)){continue;}
                $pending=self::pending($op,$d);$slow=$pending && $op->created_at->getTimestamp()<=now()->getTimestamp()-30;
                $sent=($op->attempt['seal']??null)==='sealed';
                $text=match(true){
                    $slow=>"This is taking longer than usual. We'll keep trying.",
                    $pending && $sent=>'Sent. Checking the result…',
                    $pending && $op->action==='lock'=>($op->policy['management']['input']['locked']??false)?'Turning on…':'Turning off…',
                    $pending=>'Saving…',
                    $op->state==='succeeded' && $op->action==='auth_code_email'=>'Email request accepted. Check the '.($d->registrar_key==='namesilo'?'admin contact email':'registrant email').'.',
                    $op->state==='succeeded' && $op->action==='contact_profile'=>'Details approved. Use the approved details to finish.',
                    $op->state==='succeeded'=>'Saved.',
                    $op->safe_code==='management_retired'=>'Replaced by your latest choice.',
                    default=>($op->action==='auth_code_email'?'The auth code email request failed.':'The '.strtolower(['lock'=>'Transfer lock','nameservers'=>'Nameservers','contacts'=>'Contacts','contact_profile'=>'Contacts','dns'=>'DNS records','srv'=>'DNS records'][$op->action]??'change').' change failed.').' Try again. If it keeps happening, contact support.',
                };
                if($op->action==='auth_code_email' && $pending && $sent && !$slow){$text='Email request sent. Acceptance is not confirmed. We will keep checking.';}
                $row=['id'=>(int)$op->id,'pending'=>$pending,'slow'=>$slow,'text'=>$text];
                $priority=$pending?2:($op->safe_code==='management_retired'?0:1);
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                $row=['id'=>(int)$op->id,'pending'=>false,'slow'=>false,'unreadable'=>true,'text'=>self::UNREADABLE];
                $priority=2;
            }
            if(!isset($priorities[$key]) || $priority>$priorities[$key]){$rows[$key]=$row;$priorities[$key]=$priority;}
        }
        return $rows;
    }
}
