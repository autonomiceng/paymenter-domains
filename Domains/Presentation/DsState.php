<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DsRegistryPolicy;
use Paymenter\Extensions\Servers\Domains\Services\{ManagementEvidence,ObservationProjection};
/** Presentation only: an Off policy never certifies an empty DS inventory. */
final class DsState
{
    public const CUSTOMER='DS changes are not offered for this TLD yet. Contact support if you need DNSSEC.';
    public const STAFF='Registry DS rules for this TLD have not been checked. Review the accepted registry DS rules before turning on changes.';
    public static function off(Domain $d): bool
    { return $d->registrar_key==='namesilo'&&(new DsRegistryPolicy)->snapshot($d->domain)===null; }
    public static function owner(OwnedDomain $page,Domain $d,bool $eligible=false): OwnedDomain
    {
        if(!$eligible||$d->status!=='active'){return $page;}
        $management=$page->management;
        $progress=self::progress($d)['ds']??null;
        if(isset($management['ds'])&&$progress&&!($progress['settled']??false)){$management['ds']['progress']=$progress;}
        if(self::off($d)){$management['ds']=['label'=>'DNSSEC','off'=>true,'actions'=>[],'url'=>null,'reason'=>self::CUSTOMER,'progress'=>$progress];}
        $history=array_values(array_filter($page->progress,fn($row)=>($row['label']??null)!=='DNSSEC'));
        foreach($d->operations()->whereIn('action',['ds_add','ds_delete'])->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->orderByDesc('id')->limit(25)->get() as $op){
            $event=self::event($op,$d);$history[]=['label'=>'DNSSEC','state'=>$event['state'],'note'=>$event['text'],'date'=>$op->updated_at?->toIso8601String(),'retry'=>false];
        }
        usort($history,fn($a,$b)=>strcmp($b['date']??'',$a['date']??''));
        return new OwnedDomain(...[...get_object_vars($page),'management'=>$management,'progress'=>$history]);
    }
    public static function progress(Domain $d): array
    {
        $rows=\Paymenter\Extensions\Servers\Domains\Services\ManagementProgress::for($d);
        if(isset($rows['ds']['id'])&&($op=$d->operations()->whereKey($rows['ds']['id'])->first())){
            $event=self::event($op,$d);$rows['ds']=[...$rows['ds'],'state'=>$event['state'],'text'=>$event['text'],'tone'=>$event['tone']];
        }
        return $rows;
    }
    public static function staff(Domain $d): array
    {
        if($d->status==='cancelled' && RecordFamilies::read($d,app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now())['cancelled_without_records']){
            return ['value'=>'Not applicable','tone'=>'gray','url'=>null,'demo'=>false,'help'=>null,
                'sub'=>'Registration cancelled; no registrar records to check'];
        }
        try{$records=ObservationProjection::sections($d)['ds']['facts']['records']??null;}catch(\Illuminate\Contracts\Encryption\DecryptException){$records=null;}
        $fact=['value'=>self::off($d)?'Off':($records===null?'Unknown':($records===[]?'Off':'On')),'tone'=>'gray','url'=>null,'demo'=>false,'help'=>null,
            'sub'=>self::off($d)?($d->tld?str_replace('this TLD','.'. $d->tld,self::STAFF):self::STAFF):($records===null?'Current DS records have not been confirmed. Sync this domain.':($records===[]?'The registrar confirmed there are no DS records.':implode('; ',array_map(fn($ds)=>'Key tag '.$ds['key_tag'].', algorithm '.$ds['algorithm'].', digest type '.$ds['digest_type'].': '.$ds['digest'],$records))))];
        $progress=\Paymenter\Extensions\Servers\Domains\Services\ManagementProgress::for($d)['ds']??null;
        if($progress&&($op=$d->operations()->whereKey($progress['id'])->first())&&!($progress['settled']??false)){
            $event=self::event($op,$d,true);$fact['value']=$event['state'];$fact['tone']=$event['tone'];
            $fact['progress']=['text'=>$event['text'],'request'=>$op->id,'waiting'=>$progress['pending']??false,'tone'=>$event['tone']];
        }
        return $fact;
    }
    public static function event(Operation $op,Domain $d,bool $staff=false): array
    {
        try{return self::readableEvent($op,$d,$staff);}catch(\Illuminate\Contracts\Encryption\DecryptException){return ['state'=>'Under review','tone'=>$staff?'warning':'info','text'=>$staff?'DS evidence cannot be read. Check request #'.$op->id.'.':'We’re checking this change. Nothing to do yet.'];}
    }
    private static function readableEvent(Operation $op,Domain $d,bool $staff): array
    {
        $resolved=ManagementEvidence::resolved($op,$d);
        if($op->state==='succeeded'&&$resolved){
            $empty=$op->action==='ds_delete'&&(\Paymenter\Extensions\Servers\Domains\Services\ManagementReadRecovery::latest($op)['facts']['records']??null)===[];
            return ['state'=>'Completed','tone'=>'success','text'=>$staff?($empty?'No DS records remain. A complete independent read confirmed the empty list.':'The exact record change and all other DS records were checked at the registrar.'):
                ($empty?'The registrar confirmed the last DS record was deleted. DNSSEC is Off.':'The registrar confirmed the DS change. Other records are unchanged.')];
        }
        if(in_array($op->state,['uncertain','held'],true)||$op->state==='succeeded'||$op->state==='rejected'&&!$resolved){
            return ['state'=>'Under review','tone'=>$staff?'warning':'info','text'=>$staff?'The result is not confirmed. Check request #'.$op->id.' before sending another change.':'We could not confirm the result yet. Nothing to do yet.'];
        }
        if($op->state==='rejected'&&$resolved){return ['state'=>'Failed','tone'=>'danger','text'=>$staff?'Check request #'.$op->id.'.':'The registrar did not accept this change. Contact support.'];}
        $sent=($op->attempt['seal']??null)==='sealed';
        if(!$sent){return ['state'=>'Unknown','tone'=>'info','text'=>$staff?'The registrar did not return a complete DS list. Nothing was sent. Check request #'.$op->id.'.':'We could not load your DS records. Nothing was sent. We will try again shortly.'];}
        $slow=$op->created_at&&$op->created_at->getTimestamp()<=now()->getTimestamp()-30;
        return ['state'=>'Checking the result…','tone'=>'info','text'=>$staff?($slow?'This is taking longer than usual. We will keep checking. ':'The record has been sent. ').'Waiting for an independent registrar read. Check request #'.$op->id.'.':($slow?'This is taking longer than usual. We’re still checking. Nothing to do yet.':'Sent. We’re checking the result with the registrar.')];
    }
}
