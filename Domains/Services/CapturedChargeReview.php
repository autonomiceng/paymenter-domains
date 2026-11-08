<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Review of an incurred charge. No new send, charge, refund or replacement of the original proof. */
final class CapturedChargeReview
{
    private static ?int $writing=null;
    private static ?int $completing=null;
    private const KEYS=['captured_charge_reads','captured_charge_observations','captured_charge_approval'];
    public static function hasProof(Operation $op):bool
    {try{return $op->action==='renew'&&$op->registrar_key==='enom'&&$op->term>1&&in_array($op->safe_code,['actual_charge_changed','actual_cost_overrun'],true)&&is_array($op->attempt['reconciliation']??null);}catch(\Throwable){return false;}}
    public static function guard(Operation $op):void
    {
        $old=$op->getOriginal('policy')??[];$new=$op->policy??[];
        foreach(self::KEYS as$key){if(($old[$key]??null)!==($new[$key]??null)&&self::$writing!==spl_object_id($op)){throw new \DomainException('Captured charge review evidence is protected.');}}
    }
    public static function completionAllowed(Operation $op):bool
    {return self::$completing===spl_object_id($op);}
    private static function save(Operation $op,array $attributes,bool $complete=false):void
    {
        if(self::$writing!==null||DB::transactionLevel()!==1){throw new \LogicException('Captured charge review requires the outer locked transaction.');}
        self::$writing=spl_object_id($op);self::$completing=$complete?self::$writing:null;
        try{$op->update($attributes);}finally{self::$writing=self::$completing=null;}
    }
    private static function identity(Operation $op):string
    {return hash('sha256',json_encode([$op->operation_key,$op->authority_generation,$op->account_fingerprint,$op->attempt,$op->cost],JSON_THROW_ON_ERROR));}
    /** Consume the last audited resume before I/O, so another worker cannot reuse it. */
    public static function reserve(Operation $op,Clock $clock):array
    {
        $policy=$op->policy;$reads=$policy['captured_charge_reads']??[];$resumes=$policy['renewal_resumptions']??[];$actions=$policy['staff_actions']??[];
        if(!is_array($actions)||!is_array($resumes)||!array_is_list($resumes)||!$resumes){throw new \DomainException('No audited charge observation.');}
        $audits=array_values(array_filter($actions,static fn($a)=>($a['action']??null)==='resume_reads'&&($a['phase']??null)==='completed'));$audit=$audits[count($resumes)-1]??null;
        if(!self::hasProof($op)||$op->state!=='uncertain'||($policy['renewal_execution']['reason']??null)!=='resumed'
            ||!is_array($reads)||!array_is_list($reads)||count($reads)>=20||!is_array($resumes)||!array_is_list($resumes)||!$resumes
            ||($audit['action']??null)!=='resume_reads'||($audit['phase']??null)!=='completed'||($audit['operation_key']??null)!==$op->operation_key
            ||($audit['generation']??null)!==$op->authority_generation||!is_int($audit['actor_id']??null)||$audit['actor_id']<1||!is_string($audit['key']??null)
            ||collect($reads)->contains(fn($r)=>($r['resume']??null)===count($resumes))){throw new \DomainException('No unused audited charge observation.');}
        $row=['resume'=>count($resumes),'key'=>$audit['key'],'actor_id'=>$audit['actor_id'],'operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,
            'fingerprint'=>$op->account_fingerprint,'attempt_hash'=>self::identity($op),'at'=>$clock->now()->format(DATE_ATOM)];
        $policy['captured_charge_reads']=[...$reads,$row];$policy['renewal_execution']=['staff_alert'=>true,'reason'=>$op->safe_code,'refundable_later'=>false];
        self::save($op,['policy'=>$policy]);return $row;
    }
    public static function retain(Operation $op,array $read,?array $proof,Clock $clock):void
    {
        $policy=$op->policy;$reads=$policy['captured_charge_reads']??[];$observations=$policy['captured_charge_observations']??[];
        if(!self::hasProof($op)||$op->state!=='uncertain'||!in_array($read,$reads,true)||$read['attempt_hash']!==self::identity($op)
            ||!is_array($observations)||!array_is_list($observations)||count($observations)>=20||collect($observations)->contains(fn($r)=>($r['key']??null)===$read['key'])){throw new \DomainException('Charge observation identity changed.');}
        if($proof&&(($proof['operation_key']??null)!==$op->operation_key||($proof['generation']??null)!==$op->authority_generation
            ||($proof['fingerprint']??null)!==$op->account_fingerprint||($proof['query']??null)!==['name'=>$op->domain->domain,'action'=>'renew','years'=>$op->term])){throw new \DomainException('Foreign charge observation.');}
        $policy['captured_charge_observations']=[...$observations,[...$read,'observed_at'=>$clock->now()->format(DATE_ATOM),'proof'=>$proof]];
        $policy['renewal_execution']=['staff_alert'=>true,'reason'=>$op->safe_code,'refundable_later'=>false];
        self::save($op,['policy'=>$policy]);
    }
    /** A new or unavailable charge cannot replace the captured expense. Staff can approve only the same complete proof. */
    public static function approvable(Operation $op):bool
    {
        try{if(!self::hasProof($op)||$op->state!=='uncertain'||$op->lease_expires_at!==null||$op->completed_at!==null){return false;}
        $p=$op->policy;$reads=$p['captured_charge_reads']??[];$observations=$p['captured_charge_observations']??[];
        if(!is_array($reads)||!array_is_list($reads)||!$reads||!is_array($observations)||!array_is_list($observations)||!$observations){return false;}$read=end($reads);$seen=end($observations);
        return $read['resume']===count($p['renewal_resumptions']??[])&&$seen['key']===$read['key']&&$seen['attempt_hash']===self::identity($op)
            &&$seen['proof']===($op->attempt['reconciliation']??null)&&!isset($p['captured_charge_approval']);}catch(\Throwable){return false;}
    }
    public static function approve(Operation $op,array $l,AccountBindings $bindings,Clock $clock):void
    {
        StaffOperationReview::requirePermission($l['staff']??null,'approve_charge',$op);
        if(!self::approvable($op)){throw new StaffReviewRefused('Read the original charge again. A different or unavailable result cannot approve the captured expense.');}
        $d=$l['domain'];$binding=AccountBindings::binding($l['account']);$proof=$op->attempt['reconciliation'];$charge=$proof['charge']??null;
        $bindings->requireReadableLocked($l['server'],$l['account']);RenewalFunding::requireCurrent($op,$l,true);
        ReconciliationEvidence::attempt($op,$d,$binding);
        if($binding->environment!==\Paymenter\Extensions\Servers\Domains\Data\Environment::Test||$proof['source']!=='captured_order'
            ||$proof['operation_key']!==$op->operation_key||$proof['generation']!==$op->authority_generation||$proof['fingerprint']!==$op->account_fingerprint
            ||$proof['query']!==['name'=>$d->domain,'action'=>'renew','years'=>$op->term]||$proof['provider_domain_id']!==$d->provider_domain_id
            ||$charge!==($op->cost['actual']??null)||($charge['currency']??null)!=='USD'||!is_string($proof['order_reference']??null)
            ||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$proof['expiry']??'')||($op->expected_expires_at&&$op->expected_expires_at->format('Y-m-d')!==$proof['expiry'])){throw new StaffReviewRefused('Captured charge identity changed.');}
        $policy=$op->policy;$observations=$policy['captured_charge_observations'];$seen=end($observations);
        $policy['captured_charge_approval']=['actor_id'=>(int)$l['staff']->id,'at'=>$clock->now()->format(DATE_ATOM),'read'=>$seen['key'],
            'proof_hash'=>hash('sha256',json_encode($proof,JSON_THROW_ON_ERROR)),'charge'=>$charge];
        $policy['renewal_execution']=['staff_alert'=>true,'reason'=>'captured_charge_approved','refundable_later'=>false];
        self::save($op,['state'=>'succeeded','policy'=>$policy,'provider_order_id'=>$proof['order_reference'],'confirmed_expires_at'=>$proof['expiry'],
            'completed_at'=>$clock->now(),'lease_expires_at'=>null],true);
        $d->update(['status'=>'active','provider_expires_at'=>$proof['expiry'],'provider_order_id'=>$proof['order_reference'],'confirmed_revision'=>$d->confirmed_revision+1,'last_synced_at'=>$clock->now(),
            'renewal_policy'=>[...$d->renewal_policy,'automatic_spend_paused'=>true,'billing_anchor'=>['expiry'=>$proof['expiry'],'operation_key'=>$op->operation_key,
                ...($l['identity_source']['kind']==='adoption'?['kind'=>'completed_operation','confirmed_revision'=>$d->confirmed_revision+1]:[])]]]);
        ComplimentaryInvoices::complete($op);
    }
}
