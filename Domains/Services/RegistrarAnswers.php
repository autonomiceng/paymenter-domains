<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence,ExpiryPrecision};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** An account-specific support answer is evidence, never permission to resend a purchase. */
class RegistrarAnswers
{
    private static ?array $writing=null;
    public static function guard(Operation $op): void
    {
        $old=$op->getOriginal('policy')['enom_answer']??null;$new=$op->policy['enom_answer']??null;
        if($old!==$new&&($old!==null||self::$writing!==[$op->id,$new])){throw new \DomainException('Registrar answer requires a current audited review.');}
    }
    public static function identity(Operation $op): string
    {$attempt=$op->attempt??[];unset($attempt['reconciliation']);return hash('sha256',json_encode([$op->operation_key,$op->account_fingerprint,$op->authority_generation,$attempt,$op->request_fingerprint],JSON_THROW_ON_ERROR));}
    public static function capturedReference(Operation $op): ?string
    {
        $a = $op->attempt ?? []; $id = $a['evidence']['registration']['orderReference'] ?? null;
        return $op->registrar_key === 'enom' && $op->action === 'register' && ($a['seal'] ?? null) === 'sealed'
            && ($a['execution']['disposition'] ?? null) === 'unknown' && ($a['execution']['send_state'] ?? null) === 'sent'
            && is_string($id) && preg_match('/^[1-9][0-9]{0,19}$/D', $id) ? $id : null;
    }
    public static function eligible(Operation $op,\DateTimeImmutable $now): bool
    {
        if($op->action==='transfer'){return TransferRejections::eligible($op);}
        try{$at=new \DateTimeImmutable($op->attempt['dispatch_attempted_at']??'invalid');}catch(\Throwable){return false;}
        return in_array($op->registrar_key,['enom','namesilo'],true)&&in_array($op->action,['register','renew'],true)
            &&in_array($op->state,['running','pending','uncertain'],true)&&!($op->state==='running'&&$op->lease_expires_at!==null&&$op->lease_expires_at>$now)
            &&($op->attempt['seal']??null)==='sealed'&&$at<=$now->modify('-24 hours')
            &&($op->provider_order_id===null||$op->provider_order_id===self::capturedReference($op))&&($op->registrar_key==='namesilo'||!isset($op->attempt['registration'])&&!isset($op->attempt['renewal']))
            &&!isset($op->policy['enom_answer'])&&!isset($op->attempt['reconciliation']);
    }
    public static function record(StaffSettingsReview $review,array $input,\DateTimeImmutable $now): void
    {
        // Actor, account and invoice locks follow the same order as all other financial writers.
        OperationClaims::outside();
        if($review->action!=='enom_answer'||$review->tldId!==null){throw new \DomainException('Registrar answer review required.');}
        $seed=Operation::findOrFail((int)($input['operation_id']??0))->domain()->firstOrFail();
        $id=DB::transaction(function()use($review,$input,$now,$seed):int{
            $l=HoldingLocks::take($seed,$review->actorId);$review->lockActor();$review->check();
            $op=$l['operations']->firstWhere('id',(int)($input['operation_id']??0));$d=$l['domain'];
            if(!$op||$op->server_id!==$review->serverId||!self::eligible($op,$now)||($input['attempt_hash']??null)!==self::identity($op)
                ||$op->account_fingerprint!==$l['account']->account_fingerprint||$op->account_fingerprint!==$d->account_fingerprint
                ||$op->authority_generation!==$d->authority_generation){throw new \DomainException('This request changed. Reload the page and select it again.');}
            app(AccountBindings::class)->requireReadableLocked($l['server'],$l['account']);
            $attempt=ReconciliationEvidence::attempt($op,$d,CatalogueAcceptances::binding($l['account']));
            if($op->action!=='transfer'&&($attempt->providerReference!==null||$attempt->executionFacts!==null)&&!$attempt->executionFacts?->capturedUnknown()
                &&!($op->registrar_key==='namesilo'&&$attempt->providerReference===null)){throw new \DomainException('This request already has a registrar order. Check that order instead.');}
            $outcome=$input['outcome']??null;$reference=trim((string)($input['order_reference']??''));
            if ($attempt->executionFacts?->capturedUnknown() && ($outcome !== 'accepted' || $reference !== $attempt->providerReference)) { throw new \DomainException('Use the exact saved registrar order number and its accepted outcome.'); }
            $case=ManagementPayload::text($input['case_reference']??null,128);$evidence=ManagementPayload::text($input['evidence']??null,2000);
            $transfer=$op->action==='transfer';
            if(($input['confirmed']??false)!==true||!in_array($outcome,$transfer?['transfer_rejected']:['accepted','not_processed'],true)
                ||($outcome!=='not_processed'?!preg_match('/^[1-9][0-9]{0,19}$/D',$reference):$reference!=='')){
                throw new \DomainException('Record the registrar’s final answer for this account and attempt.');}
            if($outcome==='not_processed'&&($op->provider_order_id!==null||(isset($op->attempt['registration'])||isset($op->attempt['renewal']))
                ||isset($op->cost['actual'])||($op->attempt['execution']['send_state']??null)==='sent')){throw new \DomainException('This answer conflicts with the saved registrar result. Ask the registrar to check it.');}
            if($op->provider_order_id!==null&&$reference!==$op->provider_order_id){throw new \DomainException('Registrar order number differs from the saved result.');}
            $answer=['identity'=>self::identity($op),'operation_key'=>$op->operation_key,'fingerprint'=>$op->account_fingerprint,
                'generation'=>$op->authority_generation,'actor_id'=>$review->actorId,'at'=>$now->format(DATE_ATOM),
                'outcome'=>$outcome,'order_reference'=>$reference?:null,'case_reference'=>$case,'evidence'=>$evidence];
            if($op->registrar_key==='namesilo'){
                $answer['account']=['registrar'=>$op->registrar_key,'server_id'=>$op->server_id,'domain_id'=>$op->domain_id,'service_id'=>$op->service_id];
                if($outcome==='accepted'){
                    try{$date=new DateEvidence((string)($input['processed_at']??''),ExpiryPrecision::Instant,'namesilo_support_order',(new \DateTimeImmutable((string)($input['processed_at']??'')))->getTimezone()->getName());}
                    catch(\Exception){throw new \DomainException('Record the exact order processing time with its UTC offset, confirmed by the registrar.');}
                    if(new \DateTimeImmutable($date->value)<$attempt->windowStart||new \DateTimeImmutable($date->value)>$attempt->windowEnd){throw new \DomainException('The order processing time differs from this attempt.');}
                    $answer['processed_at']=$date->value;
                }
            }
            if($transfer){$answer=[...$answer,...TransferRejections::evidence($op,$l,$input)];}
            if($outcome==='not_processed'){
                if(!$l['staff']->hasPermission('admin.domains.manage')||!$l['staff']->hasPermission('admin.invoices.update')){throw new \DomainException('Refund permission required.');}
                RefundPayment::requireCurrent($op,$l);
            }
            $policy=[...$op->policy,'enom_answer'=>$answer];
            if($transfer){$policy['reconciliation']['staff_alert']=false;$policy['reconciliation']['reason']=TransferRejections::CODE;}
            if($outcome==='accepted'){$policy['renewal_polls']=0;$policy['reconciliation']['polls']=0;}
            self::$writing=[$op->id,$answer];
            try{$op->update(['policy'=>$policy,
                ...($transfer?['state'=>'rejected','safe_code'=>TransferRejections::CODE,'completed_at'=>$now,
                    'cost'=>[...($op->cost??[]),'actual'=>['amount'=>$answer['absorbed_fee'],'currency'=>'USD']],
                    'funding'=>[...($op->funding??[]),'refund_authorization'=>['actor_id'=>$review->actorId,'reason'=>'transfer_fee_absorbed','at'=>$now->format(DATE_ATOM),'operation_key'=>$op->operation_key,'generation'=>$op->authority_generation]]]:[]),
                ...($outcome==='not_processed'?['state'=>'rejected','safe_code'=>'refund_approved','lease_expires_at'=>null,
                    'funding'=>[...($op->funding??[]),'refund_authorization'=>['actor_id'=>$review->actorId,'reason'=>$op->registrar_key==='enom'?'enom_not_processed':'registrar_not_processed','at'=>$now->format(DATE_ATOM),'operation_key'=>$op->operation_key,'generation'=>$op->authority_generation]]]:[])]);}finally{self::$writing=null;}
            if($transfer){$d->update(['status'=>'transfer_rejected']);}
            if ($outcome === 'accepted') { RegistrarCheck::resumed($op, $now->getTimestamp()); }
            $review->input(['operation_id'=>$op->id,'attempt_hash'=>$answer['identity'],'outcome'=>$outcome,'case_reference'=>$case]);
            $review->record();return (int)$op->id;
        });
        if(in_array($input['outcome']??null,['not_processed','transfer_rejected'],true)){app(Refunds::class)->creditRejected($id);}
    }
    public static function answer(Operation $op,Domain $d): ?array
    {
        $a=$op->policy['enom_answer']??null;
        return is_array($a)&&in_array($op->registrar_key,['enom','namesilo'],true)&&in_array($op->action,['register','renew','transfer'],true)
            &&$op->domain_id===$d->id&&$op->service_id===$d->service_id&&$op->server_id===$d->server_id&&$op->registrar_key===$d->registrar_key&&($a['identity']??null)===self::identity($op)
            &&($a['operation_key']??null)===$op->operation_key&&($a['fingerprint']??null)===$d->account_fingerprint
            &&($op->registrar_key!=='namesilo'||($a['account']??null)===['registrar'=>$op->registrar_key,'server_id'=>$op->server_id,'domain_id'=>$op->domain_id,'service_id'=>$op->service_id])
            &&($a['generation']??null)===$d->authority_generation&&is_int($a['actor_id']??null)&&$a['actor_id']>0?$a:null;
    }
    public static function unprocessed(Operation $op,Domain $d): bool
    {return (self::answer($op,$d)['outcome']??null)==='not_processed'&&$op->provider_order_id===null&&$op->confirmed_expires_at===null;}
}
