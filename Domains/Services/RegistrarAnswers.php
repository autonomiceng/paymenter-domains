<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence,DateEvidence,ExpiryPrecision,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
/** An account-specific support answer is evidence, never permission to resend a purchase. */
class RegistrarAnswers
{
    private static ?array $writing=null;
    public static function guard(Operation $op): void
    {
        $old=$op->getOriginal('policy')['enom_answer']??null;$new=$op->policy['enom_answer']??null;
        if($old!==$new&&($old!==null||self::$writing!==[$op->id,$new])){throw new \DomainException('Registrar answer requires a current audited review.');}
    }
    /** Unreadable saved attempt data is never eligible, answered or offered for an answer. */
    public static function readable(Operation $op): bool
    {try{$op->attempt;return true;}catch(\Illuminate\Contracts\Encryption\DecryptException){return false;}}
    public static function identity(Operation $op): string
    {$attempt=$op->attempt??[];unset($attempt['reconciliation']);return hash('sha256',json_encode([$op->operation_key,$op->account_fingerprint,$op->authority_generation,$attempt,$op->request_fingerprint],JSON_THROW_ON_ERROR));}
    public static function capturedReference(Operation $op): ?string
    {
        if (!self::readable($op)) { return null; }
        $a = $op->attempt ?? []; $id = $a['evidence']['registration']['orderReference'] ?? null;
        return $op->registrar_key === 'enom' && $op->action === 'register' && ($a['seal'] ?? null) === 'sealed'
            && ($a['execution']['disposition'] ?? null) === 'unknown' && ($a['execution']['send_state'] ?? null) === 'sent'
            && is_string($id) && preg_match('/^[1-9][0-9]{0,19}$/D', $id) ? $id : null;
    }
    public static function eligible(Operation $op,\DateTimeImmutable $now): bool
    {
        if(!self::readable($op)){return false;}
        if($op->action==='transfer'){return TransferRejections::eligible($op);}
        try{$at=new \DateTimeImmutable($op->attempt['dispatch_attempted_at']??'invalid');}catch(\Throwable){return false;}
        return in_array($op->registrar_key,['enom','namesilo'],true)&&in_array($op->action,['register','renew'],true)
            &&in_array($op->state,['running','pending','uncertain'],true)&&!($op->state==='running'&&$op->lease_expires_at!==null&&$op->lease_expires_at>$now)
            &&($op->attempt['seal']??null)==='sealed'&&$at<=$now->modify('-24 hours')
            &&(($op->provider_order_id===null||$op->provider_order_id===self::capturedReference($op))&&($op->registrar_key==='namesilo'||!isset($op->attempt['registration'])&&!isset($op->attempt['renewal']))||self::stoppedOrder($op))
            &&!isset($op->policy['enom_answer'])&&!isset($op->attempt['reconciliation']);
    }
    /** Staff ask the registrar only after the system has read this exact attempt, unless there is nothing to read. */
    public static function readFirst(Operation $op,AttemptEvidence $attempt,\DateTimeImmutable $now): bool
    {return $op->action==='transfer'||RegistrarCheck::unreadable($op,$attempt,$now)||RegistrarCheck::attempted($op);}
    /** The staff page offers the answer under the same rule the writer repeats under its locks. */
    public static function ready(Operation $op,\DateTimeImmutable $now): bool
    {
        if(!self::eligible($op,$now)){return false;}
        try{$d=$op->domain()->firstOrFail();return self::readFirst($op,ReconciliationEvidence::attempt($op,$d,AccountBindings::binding(Account::where('server_id',$op->server_id)->sole())),$now);}
        catch(\Throwable){return false;}
    }
    /** A sent Enom renewal that kept Enom's order number, with no proof or charge, whose checks stopped at the limit. Staff
     * then record either answer. A staff restart resets the count, so the answer waits for checks to stop again. */
    public static function stoppedOrder(Operation $op): bool
    {
        if(!self::readable($op)){return false;}
        $a=$op->attempt??[];$id=$op->provider_order_id;
        return $op->registrar_key==='enom'&&$op->action==='renew'&&is_string($id)&&preg_match('/^[1-9][0-9]{0,19}$/D',$id)
            &&($a['renewal']['order_reference']??null)===$id&&($a['execution']['send_state']??null)==='sent'
            &&in_array($a['execution']['disposition']??null,['accepted','accepted_with_discrepancy'],true)
            &&!isset($a['reconciliation'])&&!isset($op->cost['actual'])&&$op->confirmed_expires_at===null&&!RenewalCompletion::capturedChargeHeld($op)
            &&(int)($op->policy['renewal_polls']??0)>=OperationReconciliation::POLL_LIMIT;
    }
    /** A check of this renewal attempt saw a matching charge or a later expiry, or the domain already shows one. Not processed is then refused. */
    public static function renewalProof(Operation $op,Domain $d): bool
    {
        // The attempt's before-expiry for a renewal is its base expiry (ReconciliationEvidence::attempt).
        $before=$op->base_expires_at?->format('Y-m-d');$seen=$op->policy['renewal_proof']??null;
        return $before===null||isset($op->cost['actual'])||isset($op->attempt['reconciliation'])||$op->confirmed_expires_at!==null||RenewalCompletion::capturedChargeHeld($op)
            ||is_array($seen)&&is_string($seen['identity']??null)&&hash_equals(self::identity($op),$seen['identity'])
            ||($d->provider_expires_at!==null&&$d->provider_expires_at->format('Y-m-d')>$before);
    }
    /** One fresh read, the higher of the domain info and expiry replies, never inside a lock. Not processed returns money, so
     * it needs a current answer: every read must succeed in time, Enom's Expiry included. A later expiry refuses, and that
     * proof is kept on the attempt first, even when a later read in the same check failed. */
    private static function requireUnrenewed(Operation $op,Domain $d,\DateTimeImmutable $now): void
    {
        $before=$op->base_expires_at?->format('Y-m-d');$ctx=null;$binding=null;$confirmed=false;
        try{
            $binding=AccountBindings::binding(Account::where('server_id',$op->server_id)->sole());$clock=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class);
            $deadline=$clock->now()->modify('+30 seconds');
            // Strict: a failed read throws instead of returning an unknown result.
            $ctx=new RequestContext($binding,RequestOrigin::Interactive,$deadline,new RequestBudget(4),$clock,Runtime::limiter(),true);
            $reader=app(AccountBindings::class)->reader((int)$op->server_id);$reader->readRenewalDomain($d->domain,$ctx);$info=$ctx->seen()?->domain;
            // Enom's expiry read can lag or lead its info read (961c40e8): it must answer too, and the higher counts.
            $expiry=method_exists($reader,'expiry')?$reader->expiry($d->domain,$ctx):null;
            $confirmed=$info!==null&&($expiry===null||$expiry->status==='success'&&isset($expiry->facts()['expiry']))&&$clock->now()<$deadline;
        }catch(\PDOException $e){throw $e;}catch(\Throwable){$confirmed=false;}
        $seen=$ctx?->seen()?->domain;
        $matches=$seen!==null&&$binding!==null&&$seen->binding->matches($binding)&&$seen->name===$d->domain;
        if($before!==null&&$matches&&substr($seen->expiry->value,0,10)>$before){
            self::keepProof($op,$d,$now);
            throw new \DomainException('The registrar now shows a later expiry for this domain, so this renewal can’t be recorded as not processed.');
        }
        if($before===null||!$confirmed||!$matches){
            throw new \DomainException('The registrar could not confirm the domain’s expiry just now, so this can’t be recorded as not processed yet. Try again later.');
        }
    }
    /** Under the normal locks, the later expiry the fresh read saw stays on this attempt, so a lagging retry cannot return its money. */
    private static function keepProof(Operation $chosen,Domain $seed,\DateTimeImmutable $now): void
    {
        $identity=self::identity($chosen);
        FreshTransaction::run(static function()use($seed,$chosen,$identity,$now):void {
            $l=HoldingLocks::take($seed);$op=$l['operations']->firstWhere('id',$chosen->id);
            if(!$op||self::identity($op)!==$identity||($op->policy['renewal_proof']['identity']??null)===$identity){return;}
            $op->update(['policy'=>[...($op->policy??[]),'renewal_proof'=>['identity'=>$identity,'at'=>$now->format(DATE_ATOM)]]]);
        });
    }
    /** Called in a check's locked save: a matching charge or a later expiry this check saw stays on the attempt. */
    public static function sawProof(array $policy,Operation $op,AttemptEvidence $attempt,\Paymenter\Extensions\Servers\Domains\Data\Evidence $facts,\DateTimeImmutable $now): array
    {
        $d=$facts->domain;$before=$attempt->beforeExpiry?substr($attempt->beforeExpiry->value,0,10):null;
        $later=$d&&$d->binding->matches($attempt->binding)&&$d->name===$attempt->query->name&&$d->owned===true&&$before!==null
            &&$d->expiry!==null&&substr($d->expiry->value,0,10)>$before;
        if(($facts->candidates!==[]||$later)&&($policy['renewal_proof']['identity']??null)!==self::identity($op)){$policy['renewal_proof']=['identity'=>self::identity($op),'at'=>$now->format(DATE_ATOM)];}
        return $policy;
    }
    public static function record(StaffSettingsReview $review,array $input,\DateTimeImmutable $now): void
    {
        // Actor, account and invoice locks follow the same order as all other financial writers.
        OperationClaims::outside();
        if($review->action!=='enom_answer'||$review->tldId!==null){throw new \DomainException('Registrar answer review required.');}
        $chosen=Operation::findOrFail((int)($input['operation_id']??0));$seed=$chosen->domain()->firstOrFail();
        // Not processed reads the registrar once more, before any lock: a renewal that went through shows a later expiry.
        if(($input['outcome']??null)==='not_processed'&&$chosen->action==='renew'){self::requireUnrenewed($chosen,$seed,$now);}
        $id=FreshTransaction::run(function()use($review,$input,$now,$seed):int{
            $l=HoldingLocks::take($seed,$review->actorId);$review->lockActor();$review->check();
            $op=$l['operations']->firstWhere('id',(int)($input['operation_id']??0));$d=$l['domain'];
            if(!$op||$op->server_id!==$review->serverId||!self::eligible($op,$now)||($input['attempt_hash']??null)!==self::identity($op)
                ||$op->account_fingerprint!==$l['account']->account_fingerprint||$op->account_fingerprint!==$d->account_fingerprint
                ||$op->authority_generation!==$d->authority_generation){throw new \DomainException('This request changed. Reload the page and select it again.');}
            app(AccountBindings::class)->requireReadableLocked($l['server'],$l['account']);
            $attempt=ReconciliationEvidence::attempt($op,$d,AccountBindings::binding($l['account']));
            // A renewal with Enom's order number, once its checks stopped: staff record whether Enom processed it.
            $unproven=self::stoppedOrder($op);
            if(!$unproven&&$op->action!=='transfer'&&($attempt->providerReference!==null||$attempt->executionFacts!==null)&&!$attempt->executionFacts?->capturedUnknown()
                &&!($op->registrar_key==='namesilo'&&$attempt->providerReference===null)){throw new \DomainException('This request already has a registrar order. Check that order instead.');}
            if(!self::readFirst($op,$attempt,$now)){throw new \DomainException('Use Check status to read this attempt first, then record the registrar’s answer.');}
            $outcome=$input['outcome']??null;$reference=trim((string)($input['order_reference']??''));
            if ($attempt->executionFacts?->capturedUnknown() && ($outcome !== 'accepted' || $reference !== $attempt->providerReference)) { throw new \DomainException('Use the exact saved registrar order number and its accepted outcome.'); }
            $transfer=$op->action==='transfer';$credit=$unproven&&$outcome==='not_processed';
            // The case number is optional.
            $case=trim((string)($input['case_reference']??''))===''?null:ManagementPayload::text($input['case_reference'],128);
            $evidence=ManagementPayload::text($input['evidence']??null,2000);
            if(($input['confirmed']??false)!==true||!in_array($outcome,$transfer?['transfer_rejected']:['accepted','not_processed'],true)
                ||($outcome!=='not_processed'?!preg_match('/^[1-9][0-9]{0,19}$/D',$reference):$reference!=='')){
                throw new \DomainException('Record the registrar’s final answer for this account and attempt.');}
            // The one guard on Not processed: no check saw a charge or a renewal.
            if($outcome==='not_processed'&&$op->action==='renew'&&self::renewalProof($op,$d)){throw new \DomainException('A check saw a charge or a new expiry for this renewal, so it can’t be recorded as not processed.');}
            if(!$unproven&&$outcome==='not_processed'&&($op->provider_order_id!==null||(isset($op->attempt['registration'])||isset($op->attempt['renewal']))
                ||isset($op->cost['actual'])||($op->attempt['execution']['send_state']??null)==='sent')){throw new \DomainException('This answer conflicts with the saved registrar result. Ask the registrar to check it.');}
            if($op->provider_order_id!==null&&!$credit&&$reference!==$op->provider_order_id){throw new \DomainException('Registrar order number differs from the saved result.');}
            $answer=['identity'=>self::identity($op),'operation_key'=>$op->operation_key,'fingerprint'=>$op->account_fingerprint,
                'generation'=>$op->authority_generation,'actor_id'=>$review->actorId,'at'=>$now->format(DATE_ATOM),
                'outcome'=>$outcome,'order_reference'=>$reference?:null,'case_reference'=>$case,'evidence'=>$evidence,...($credit?['unproven_order'=>$op->provider_order_id]:[])];
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
                if($op->funding_basis==='complimentary'){ComplimentaryInvoices::require($op,$l);}else{RefundPayment::requireCurrent($op,$l);}
            }
            $policy=[...$op->policy,'enom_answer'=>$answer];
            if($transfer){$policy['reconciliation']['staff_alert']=false;$policy['reconciliation']['reason']=TransferRejections::CODE;}
            if($outcome==='accepted'){$policy['renewal_polls']=0;$policy['reconciliation']['polls']=0;}
            self::$writing=[$op->id,$answer];
            try{$op->update(['policy'=>$policy,
                ...($transfer?['state'=>'rejected','safe_code'=>TransferRejections::CODE,'completed_at'=>$now,
                    'cost'=>[...($op->cost??[]),'actual'=>['amount'=>$answer['absorbed_fee'],'currency'=>'USD']],
                    'funding'=>[...($op->funding??[]),'refund_authorization'=>['actor_id'=>$review->actorId,'reason'=>'transfer_fee_absorbed','at'=>$now->format(DATE_ATOM),'operation_key'=>$op->operation_key,'generation'=>$op->authority_generation]]]:[]),
                // The order number stays in the attempt and the answer; the request no longer claims an order, so the existing refund path applies.
                ...($credit?['provider_order_id'=>null]:[]),
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
        return is_array($a)&&self::readable($op)&&in_array($op->registrar_key,['enom','namesilo'],true)&&in_array($op->action,['register','renew','transfer'],true)
            &&$op->domain_id===$d->id&&$op->service_id===$d->service_id&&$op->server_id===$d->server_id&&$op->registrar_key===$d->registrar_key&&($a['identity']??null)===self::identity($op)
            &&($a['operation_key']??null)===$op->operation_key&&($a['fingerprint']??null)===$d->account_fingerprint
            &&($op->registrar_key!=='namesilo'||($a['account']??null)===['registrar'=>$op->registrar_key,'server_id'=>$op->server_id,'domain_id'=>$op->domain_id,'service_id'=>$op->service_id])
            &&($a['generation']??null)===$d->authority_generation&&is_int($a['actor_id']??null)&&$a['actor_id']>0?$a:null;
    }
    /** Not processed for a renewal with Enom's order number: its payment returns as account credit, never to the card. */
    public static function creditOnly(Operation $op,Domain $d): bool
    {$a=self::answer($op,$d);return ($a['outcome']??null)==='not_processed'&&is_string($a['unproven_order']??null);}
    public static function unprocessed(Operation $op,Domain $d): bool
    {return (self::answer($op,$d)['outcome']??null)==='not_processed'&&$op->provider_order_id===null&&$op->confirmed_expires_at===null;}
}
