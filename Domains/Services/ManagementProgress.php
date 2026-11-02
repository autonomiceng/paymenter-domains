<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
/** Stored state only. Shared by both controls and the owner status endpoint. */
final class ManagementProgress
{
    public const UNREADABLE='This change cannot be read. Contact support.';
    public const ACTIONS=['lock','nameservers','contacts','contact_profile','dns','srv','auth_code_email',
        'privacy_enable','privacy_disable','privacy_purchase','privacy_renew','ds_add','ds_delete','glue_add','glue_update','glue_delete'];
    /** The tail of a failed change's words. OwnedDomains swaps it when the customer can't make the change. */
    public const TRY_AGAIN=' Try again. If it keeps happening, contact support.';
    /** A finished change is news for this long. After that the row's value says it. */
    public const SETTLED_SECONDS = 600;
    public static function setting(string $action): string
    { return match($action) { 'contact_profile'=>'contacts','srv'=>'dns',
        'privacy_enable','privacy_disable','privacy_purchase','privacy_renew'=>'privacy','ds_add','ds_delete'=>'ds',
        'glue_add','glue_update','glue_delete'=>'glue',default=>$action }; }
    public static function pending(Operation $op, Domain $d): bool
    { return !isset($op->attempt['management_terminal']) && (!ManagementEvidence::resolved($op,$d)
        || in_array($op->state,['queued','awaiting_payment','running','pending','uncertain'],true) || ManagementEvidence::recoverable($op)); }
    public static function for(Domain $d): array
    {
        $rows=[];$priorities=[];
        $scope=$d->operations()->whereIn('action',self::ACTIONS)
            ->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint);
        $latest=(clone $scope)->selectRaw('MAX(id)')->groupBy('action');
        $retained=(clone $scope)->where(fn($q)=>$q->whereNull('safe_code')->orWhere('safe_code','!=','management_retired'))->selectRaw('MAX(id)')->groupBy('action');
        $pending=(clone $scope)->where('management_pending',true)->selectRaw('MAX(id)')->groupBy('action');
        // At most three rows per action; pending selection is independent of finished history.
        $candidates=(clone $scope)->where(fn($q)=>$q->whereIn('id',$latest)->orWhereIn('id',$retained)->orWhereIn('id',$pending))->orderByDesc('id')->limit(3*count(self::ACTIONS))->get();
        foreach($candidates as $op){
            $key=self::setting($op->action);
            try {
                if(!isset($op->policy['management'])){continue;}
                $pending=self::pending($op,$d);$slow=$pending && $op->created_at->getTimestamp()<=now()->getTimestamp()-30;
                $sent=($op->attempt['seal']??null)==='sealed';
                $text=match(true){
                    $slow=>"This is taking longer than usual. We'll keep trying.",
                    $pending && $sent=>'Sent. Checking the result…',
                    $pending && $op->action==='lock'=>($op->policy['management']['input']['locked']??false)?'Turning on…':'Turning off…',
                    $pending=>'Saving…',
                    $op->state==='succeeded' && $op->action==='auth_code_email'=>'Email request accepted. Check the '.($d->registrar_key==='namesilo'?'admin contact email':'registrant email').'.',
                    $op->state==='succeeded' && $op->action==='contact_profile'=>'Details approved. Use the approved details to finish.',
                    $op->state==='succeeded' && $op->action==='nameservers' && self::nameserversChanged($op,$d)=>'Nameservers changed after this request. Check them and change them if needed.',
                    $op->state==='succeeded'=>'Saved.',
                    $op->safe_code==='management_retired'=>'Replaced by your latest choice.',
                    default=>(ManagementEvidence::outcome($op)===RequestStatus::UNSENT?RequestStatus::UNSENT:($op->action==='auth_code_email'?'The auth code email request failed.':'The '.strtolower(['lock'=>'Transfer lock','nameservers'=>'Nameservers','contacts'=>'Contacts','dns'=>'DNS records','privacy'=>'WHOIS privacy','ds'=>'DNSSEC','glue'=>'Glue records'][self::setting($op->action)]??'change').' change failed.')).self::TRY_AGAIN,
                };
                if($op->action==='auth_code_email' && $pending && $sent && !$slow){$text='Email request sent. Acceptance is not confirmed. We will keep checking.';}
                $settled=$op->state==='succeeded'&&$op->action!=='contact_profile'&&$op->updated_at->getTimestamp()<=now()->getTimestamp()-self::SETTLED_SECONDS;
                $replaced=$op->safe_code==='management_retired';
                // What staff read in place of $text, which speaks to the customer.
                $outcome=$pending||$replaced||$op->state==='succeeded'?null:ManagementEvidence::outcome($op)??RequestStatus::UNCONFIRMED;
                $row=['id'=>(int)$op->id,'pending'=>$pending,'slow'=>$slow,'text'=>$text,'settled'=>$settled,'outcome'=>$outcome,'replaced'=>$replaced];
                $priority=$pending?2:($op->safe_code==='management_retired'?0:1);
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                $row=['id'=>(int)$op->id,'pending'=>false,'slow'=>false,'unreadable'=>true,'text'=>self::UNREADABLE];
                $priority=2;
            }
            if(!isset($priorities[$key]) || $priority>$priorities[$key]){$rows[$key]=$row;$priorities[$key]=$priority;}
        }
        return $rows;
    }
    private static function nameserversChanged(Operation $op, Domain $d): bool
    {
        $current=ObservationProjection::nameservers($d);$read=ManagementReadRecovery::latest($op);
        $saved=NameserverEvidence::fromArray(['nameservers'=>$read['facts']['nameservers']??null,'state'=>'observed',
            'source'=>$d->registrar_key==='enom'?'enom_getdns':'namesilo_domain_info','at'=>$read['at']??null]);
        if(!$current || $current->state!=='observed' || !$saved || new \DateTimeImmutable($current->at)<=new \DateTimeImmutable($saved->at)){return false;}
        $before=$saved->nameservers;$after=$current->nameservers;sort($before);sort($after);
        return $before!==$after;
    }
}
