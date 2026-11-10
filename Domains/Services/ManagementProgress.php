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
    /** The page section a change belongs to. DNS and SRV share one setting, one zone write at a time, but are separate sections. */
    public static function section(string $action): string
    { return $action==='srv'?'srv':self::setting($action); }
    /** A change's status key on its domain. Customer History and the staff Activity rail read this one word:
     * a result counts only once the registrar's readback confirms it. Null when the request is not a change. */
    public static function statusKey(Operation $op, Domain $d, bool $ordersOff = false): ?string
    {
        $p=$op->policy['management']??null;
        if ($p===null || isset($p['correction'])) { return null; }
        $resolved=ManagementEvidence::resolved($op,$d);
        return match (true) {
            in_array($op->state,['succeeded','rejected'],true) && !$resolved => 'under_review',
            $op->state==='held' && $op->safe_code==='enom_test_management_call_cap' && $resolved => 'failed',
            default => RequestStatus::key(RequestStatus::readsAs($op->state,$op->safe_code,$ordersOff)),
        };
    }
    public static function pending(Operation $op, Domain $d): bool
    { return !isset($op->attempt['management_terminal']) && (!ManagementEvidence::resolved($op,$d)
        || in_array($op->state,['queued','awaiting_payment','running','pending','uncertain'],true) || ManagementEvidence::recoverable($op)); }
    public static function for(Domain $d): array
    {
        $rows=[];$priorities=[];$finished=[];
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
                if(!isset($op->policy['management']) || isset($op->policy['management']['sequence_parent'])){continue;}
                $row=self::sequence($op,$d,self::row($op,$d));
                $priority=$row['pending']?2:($op->safe_code==='management_retired'?0:1);
            } catch (\DomainException) {
                $row=['id'=>(int)$op->id,'pending'=>true,'slow'=>false,'tone'=>'warning','text'=>self::UNREADABLE];$priority=2;
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                $row=['id'=>(int)$op->id,'pending'=>false,'slow'=>false,'unreadable'=>true,'text'=>self::UNREADABLE];
                $priority=2;
            }
            $section=self::section($op->action);
            if(!isset($priorities[$key]) || $priority>$priorities[$key]){$rows[$key]=[...$row,'section'=>$section];$priorities[$key]=$priority;}
            if(!$row['pending'] && (!isset($finished[$key][$section]) || $priority>$finished[$key][$section][1])){$finished[$key][$section]=[[...$row,'section'=>$section],$priority];}
        }
        // Pending work holds the whole setting. Otherwise each section of a shared setting keeps its own latest result.
        foreach($rows as $key=>$row){
            if(!$row['pending'] && count($finished[$key]??[])>1){$rows[$key]['sections']=array_map(fn($entry)=>$entry[0],$finished[$key]);}
        }
        return $rows;
    }
    private static function row(Operation $op, Domain $d): array
    {
        $deferrals=$op->attempt['management_deferrals']??[];
        $since=$deferrals?new \DateTimeImmutable(end($deferrals)):$op->created_at;
        $pending=self::pending($op,$d);$slow=$pending && $since->getTimestamp()<=now()->getTimestamp()-120;
        $sent=($op->attempt['seal']??null)==='sealed';
        $refused=!$pending && $op->action==='nameservers' && $op->safe_code==='management_provider_refused';
        $text=match(true){
            $slow=>"This is taking longer than usual. We'll keep trying.",
            $pending && $sent=>'Sent. Checking the result…',
            $pending && $op->action==='lock'=>($op->policy['management']['input']['locked']??false)?'Turning on…':'Turning off…',
            $pending=>'Saving…',
            $op->state==='succeeded' && $op->action==='auth_code_email'=>'Request accepted. '.OwnedDomains::authCodeSender($d).' emails the code to the '.OwnedDomains::authCodeRecipient($d).'.',
            $op->state==='succeeded' && $op->action==='contact_profile'=>'Details approved. Use the approved details to finish.',
            $op->state==='succeeded' && $op->action==='nameservers' && self::nameserversChanged($op,$d)=>'Nameservers changed after this request. Check them and change them if needed.',
            $op->state==='succeeded'=>'Saved.',
            $op->safe_code==='management_retired'=>'Replaced by your latest choice.',
            $refused=>self::nameserversRefused($op),
            default=>(ManagementEvidence::outcome($op)===RequestStatus::UNSENT?RequestStatus::UNSENT:($op->action==='auth_code_email'?'The auth code email request failed.':'The '.(['lock'=>'transfer lock','nameservers'=>'nameservers','contacts'=>'contacts','dns'=>'DNS records','srv'=>'SRV records','privacy'=>'WHOIS privacy','ds'=>'DNSSEC','glue'=>'glue records'][self::section($op->action)]??'change').' change failed.')).self::TRY_AGAIN,
        };
        if($op->action==='auth_code_email' && $pending && $sent && !$slow){$text='Email request sent. Acceptance is not confirmed. We will keep checking.';}
        // A refused nameserver change stays until a later registrar read shows the nameservers in use. History keeps it.
        $settled=$refused?self::readSince($op,$d):$op->state==='succeeded'&&$op->action!=='contact_profile'&&$op->updated_at->getTimestamp()<=now()->getTimestamp()-self::SETTLED_SECONDS;
        $replaced=$op->safe_code==='management_retired';
        // What staff read in place of $text, which speaks to the customer.
        $outcome=$pending||$replaced||$op->state==='succeeded'?null:ManagementEvidence::outcome($op)??RequestStatus::UNCONFIRMED;
        $row=['id'=>(int)$op->id,'pending'=>$pending,'slow'=>$slow,'text'=>$text,'settled'=>$settled,'outcome'=>$outcome,'replaced'=>$replaced,
            ...($refused?['tone'=>'bad','support'=>true]:[])];
        return $row;
    }

    /** Retained sequence facts only: an unsent parent must not hide its prerequisite's outcome. */
    private static function sequence(Operation $op, Domain $d, array $row): array
    {
        if (isset($op->policy['management']['sequence']) && !isset($op->attempt['management_terminal'])) {
            $done=0;$members=ManagementSequence::members($op,ManagementSequence::retained($op,$d));$blocked=null;
            foreach ($members as $child) {
                if ($child->state==='succeeded' && ManagementEvidence::resolved($child,$d)) { $done++; }
                elseif ($blocked===null) { $blocked=$child; }
            }
            // Owner and staff read the same request number and counts.
            $total=$members->count();$request='Request #'.$op->id.': ';
            if ($done===$total) { return [...$row,'text'=>$request.($total===1?'1 change verified.':'all '.$total.' changes verified.')]; }
            $prefix=$request.$done.' of '.$total.($total===1?' change':' changes').' verified. ';
            // A later settings change cannot finish this request, but a fresh save still replaces it when the rest was never sent.
            if ((int)$d->service->user_id!==(int)$d->user_id
                || $op->policy['management']['sequence']['configuration']!==ManagementSequence::currentConfiguration($d) && !ManagementSequence::unsent($op,$members)) {
                return [...$row,'text'=>$prefix.'The account or ownership changed. Contact support to finish the remaining changes.','tone'=>'warning'];
            }
            if ($blocked?->state==='queued' && $row['slow']) {
                return [...$row,'text'=>$prefix.'This is taking longer than usual. Contact support to finish the remaining changes.','tone'=>'warning'];
            }
            if ($blocked && ($blocked->state==='uncertain' || $blocked->state==='held' || $blocked->state==='rejected'
                || $blocked->state==='running' && $row['slow'])) {
                $unsent=ManagementEvidence::idle($blocked);
                return [...$row,'pending'=>!$unsent,'text'=>$prefix.($unsent?'The remaining changes were not sent. Save again to retry.':'The result is not confirmed. Contact support before making more changes.'),'tone'=>'warning'];
            }
            return [...$row,'text'=>$prefix.'Saving the remaining changes.'];
        }
        $key=$op->policy['management']['prerequisite']['key']??null;
        if ($op->action!=='contacts' || !is_string($key) || isset($op->attempt['management_terminal'])) { return $row; }
        $step=$d->operations()->where('operation_key',$key)->where('action','contact_profile')
            ->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->first();
        if (!$step || ($step->policy['management']['sequence_parent']??null)!==$op->operation_key) { return $row; }
        $verified=$step->state==='succeeded' && ManagementEvidence::resolved($step,$d);
        if ($verified && $op->state==='succeeded' && ManagementEvidence::resolved($op,$d)) {
            return [...$row,'text'=>'Contact details verified.'];
        }
        // Once association was sent, its own pending/readback status is the sequence status.
        if (!ManagementEvidence::idle($op)) { return $row; }
        if ($verified) { return [...$row,'text'=>'Contact details saved. Applying them to the domain.']; }
        $row=[...self::row($step,$d),'id'=>(int)$op->id];
        if ($step->state==='uncertain' && ($step->attempt['seal']??null)==='sealed'
            && !isset($step->attempt['management_ack']['profile_id'])) {
            $row['text']="We couldn't confirm the new contact details. Nothing else was changed. Contact support and we'll sort it out.";
            $row['tone']='warning';
        }
        return $row;
    }
    /** The customer's words for a refused nameserver change: what was refused and what to check. Staff keep RequestStatus::NAMESERVERS_REFUSED. */
    private static function nameserversRefused(Operation $op): string
    {
        $servers=$op->policy['management']['input']['nameservers']??null;
        $list=is_array($servers)?implode(', ',array_filter($servers,'is_string')):'';
        // The registrar refuses the change as a whole, so the words never say each server was refused.
        return $list!==''?'Not changed: the registrar did not take the change to '.$list.'. Check that each one is a working nameserver, then try again.'
            :'Not changed: the registrar did not take this change. Check that each nameserver is working, then try again.';
    }
    private static function readSince(Operation $op, Domain $d): bool
    {
        try { $current=ObservationProjection::nameservers($d); } catch (\Throwable) { return false; }
        return $current!==null && $current->state==='observed' && $current->at!==null && new \DateTimeImmutable($current->at)>$op->updated_at;
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
