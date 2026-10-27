<?php
// Adapted from Enom d2c55e2 Models/Operation.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    use HasAccountBinding;

    protected $table = 'domains_operations';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint', 'payload', 'policy', 'cost', 'attempt', 'funding', 'funding_reason', 'authorized_by', 'request_fingerprint'];
    protected $casts = ['attempt' => 'encrypted:array', 'funding' => 'encrypted:array', 'authority_generation' => 'integer', 'payload' => 'encrypted:array', 'policy' => 'encrypted:array', 'cost' => 'encrypted:array', 'amount' => 'decimal:2', 'base_expires_at' => 'immutable_date', 'expected_expires_at' => 'immutable_date', 'confirmed_expires_at' => 'immutable_date', 'lease_expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(static function (self $operation): void {
            if ($operation->isDirty(['id', 'domain_id', 'service_id', 'server_id', 'registrar_key', 'account_fingerprint', 'authority_generation', 'action', 'term', 'currency', 'amount', 'invoice_id', 'invoice_item_id', 'operation_key', 'funding_basis', 'authorized_by', 'funding_reason'])) {
                throw new \DomainException('Operation binding and action are immutable.');
            }
        });
        static::updating(static function (self $operation): void {
            $old = $operation->getOriginal('policy') ?? []; $new = $operation->policy ?? [];
            if (isset($old['renewal']) && $operation->isDirty(['base_expires_at', 'expected_expires_at'])) {
                throw new \DomainException('Issued renewal expiry obligations are frozen.');
            }
            foreach (['native_acquisition_cancelled_at', 'native_acquisition_closure', 'unpaid_retirement', 'checkout', 'price', 'seller_revision', 'calendar', 'renewal', 'complimentary', 'management', 'privacy'] as $key) {
                if (array_key_exists($key, $old) && ($new[$key] ?? null) !== $old[$key]) { throw new \DomainException('Checkout evidence is frozen.'); }
            }
            $old = $operation->getOriginal('cost') ?? []; $new = $operation->cost ?? [];
            foreach (['estimated', 'currency', 'unit', 'actual'] as $key) {
                if (array_key_exists($key, $old) && ($new[$key] ?? null) !== $old[$key]) { throw new \DomainException('Estimated cost evidence is frozen.'); }
            }
        });
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\ContactSetup::guardSnapshot($operation));
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\TransferInputs::guard($operation));
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\OwnerSetup::guard($operation));
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\ManagementEvidence::guard($operation));
        static::updating(static function (self $operation): void {
            $before = $operation->getOriginal('policy') ?? []; $after = $operation->policy ?? [];
            if (array_key_exists('contact_disposition', $before)
                && (!array_key_exists('contact_disposition', $after) || $after['contact_disposition'] !== $before['contact_disposition'])) {
                throw new \DomainException('Contact disposition is frozen.');
            }
            foreach (['staff_actions'=>100,'renewal_resumptions'=>20] as $key=>$limit) {
                $history=$before[$key]??[]; $next=$after[$key]??[];
                if(!is_array($history)||!is_array($next)||!array_is_list($next)||count($next)>$limit||count($next)<count($history)
                    ||array_slice($next,0,count($history))!==$history){throw new \DomainException('Staff review history is append-only and bounded.');}
            }
            if(array_key_exists('renewal_replacement',$before)&&($after['renewal_replacement']??null)!==$before['renewal_replacement']){
                throw new \DomainException('Renewal replacement review is retained.');
            }
            $history = $before['reconciliation_resumptions'] ?? []; $next = $after['reconciliation_resumptions'] ?? [];
            if (!is_array($history) || !is_array($next) || !array_is_list($next) || count($next) > 20
                || count($next) < count($history) || array_slice($next, 0, count($history)) !== $history) { throw new \DomainException('Reconciliation resumptions are append-only.'); }
            if (array_key_exists('contact_readmissions', $before) || array_key_exists('contact_readmissions', $after)) {
                $history = array_key_exists('contact_readmissions', $before) ? $before['contact_readmissions'] : []; $next = $after['contact_readmissions'] ?? null;
                if (!is_array($history) || !array_is_list($history) || !is_array($next) || !array_is_list($next)
                    || count($next) > 10 || count($next) < count($history) || array_slice($next, 0, count($history)) !== $history) {
                    throw new \DomainException('Contact readmissions are append-only and bounded.');
                }
            }
            $old = $operation->getOriginal('attempt') ?? []; $new = $operation->attempt ?? [];
            $oldRefusals=$old['test_call_refusals']??[];$newRefusals=$new['test_call_refusals']??[];
            if(!is_array($oldRefusals)||!array_is_list($oldRefusals)||!is_array($newRefusals)||!array_is_list($newRefusals)
                ||count($newRefusals)>100||count($newRefusals)<count($oldRefusals)||array_slice($newRefusals,0,count($oldRefusals))!==$oldRefusals) {
                throw new \DomainException('TEST call refusal history is append-only and bounded.');
            }
            foreach($newRefusals as $refusal) {
                if(!is_array($refusal)||array_keys($refusal)!==['phase','refused_at','dispatch_attempted_at','authorized_cost']
                    ||!in_array($refusal['phase']??null,['preflight','purchase'],true)||!is_string($refusal['refused_at']??null)) {
                    throw new \DomainException('TEST call refusal evidence is invalid.');
                }
                try { if((new \DateTimeImmutable($refusal['refused_at']))->format(DATE_ATOM)!==$refusal['refused_at']) { throw new \DomainException('TEST call refusal time is invalid.'); } }
                catch(\Throwable) { throw new \DomainException('TEST call refusal time is invalid.'); }
                if($refusal['phase']==='preflight') {
                    if($refusal['dispatch_attempted_at']!==null||$refusal['authorized_cost']!==null) { throw new \DomainException('Preflight refusal cannot have sealed evidence.'); }
                } elseif(!is_string($refusal['dispatch_attempted_at'])||!is_array($refusal['authorized_cost'])
                    ||array_keys($refusal['authorized_cost'])!==['amount','currency']||!is_string($refusal['authorized_cost']['amount']??null)
                    ||$refusal['authorized_cost']['currency']!=='USD') { throw new \DomainException('Purchase refusal evidence is invalid.'); }
            }
            $oldFunding=$operation->getOriginal('funding')??[];$newFunding=$operation->funding??[];
            $expectedFunding=$oldFunding;unset($expectedFunding['claim'],$expectedFunding['seal']);
            $phase=($old['seal']??null)==='sealed'?'purchase':'preflight';$lastRefusal=$newRefusals?end($newRefusals):null;
            $deferrals=$old['capacity_deferrals']??[];
            $validDeferrals=!array_key_exists('capacity_deferrals',$old)||(is_array($deferrals)&&array_is_list($deferrals)&&count($deferrals)<=5
                &&!array_filter($deferrals,static fn($at)=>!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$at)));
            $safeCapReset=$operation->getOriginal('state')==='running'&&$operation->state==='held'&&in_array($operation->safe_code,['enom_test_call_cap','enom_test_transfer_call_cap'],true)
                &&in_array($operation->action,['register','transfer'],true)&&$operation->started_at===null&&$operation->request_fingerprint===null&&$operation->lease_expires_at===null
                &&$operation->provider_order_id===null&&!array_key_exists('actual',$operation->cost??[])&&!array_key_exists('execution',$operation->cost??[])
                &&!array_key_exists('dispatch_attempted_at',$operation->policy??[])
                &&is_array($lastRefusal)&&$lastRefusal===['phase'=>$phase,'refused_at'=>$lastRefusal['refused_at']??null,
                    'dispatch_attempted_at'=>$phase==='purchase'?($old['dispatch_attempted_at']??null):null,
                    'authorized_cost'=>$phase==='purchase'?($old['authorized_cost']??null):null]
                &&$new===['test_call_refusals'=>$newRefusals,...array_intersect_key($old,['capacity_deferrals'=>true])]
                &&$newRefusals===array_merge($oldRefusals,[$lastRefusal])
                &&$newFunding===$expectedFunding;
            // A proven-unsent TEST refusal can be staff-released and freshly claimed with a new
            // execution window. Preserve its refusal/deferral histories while rotating authority.
            $retryDeferrals=$old['capacity_deferrals']??[];
            $retryFunding=$newFunding;unset($retryFunding['claim'],$retryFunding['seal']);
            $freshClaim=$newFunding['claim']??null;
            $safeTestRetry=$operation->getOriginal('state')==='queued'&&$operation->state==='running'&&$operation->safe_code===null
                &&in_array($operation->action,['register','transfer'],true)&&$operation->registrar_key==='enom'&&$oldRefusals!==[]&&$oldRefusals===$newRefusals
                &&in_array($operation->getOriginal('safe_code'),[null,'write_rate_limited'],true)
                &&($old['seal']??null)==='absent'&&($old['send_state']??null)==='definitely_unsent'
                &&(!isset($old['claim_key'])||(is_string($old['claim_key'])&&preg_match('/^[a-f0-9]{32}$/D',$old['claim_key'])&&($new['claim_key']??null)!==$old['claim_key']))
                &&is_array($retryDeferrals)&&array_is_list($retryDeferrals)&&count($retryDeferrals)<=5
                &&!array_filter($retryDeferrals,static fn($at)=>!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$at))
                &&$new===['test_call_refusals'=>$oldRefusals,...array_intersect_key($old,['capacity_deferrals'=>true]),
                    'claim_key'=>$new['claim_key']??null,'seal'=>'absent','send_state'=>'definitely_unsent']
                &&is_string($new['claim_key']??null)&&preg_match('/^[a-f0-9]{32}$/D',$new['claim_key'])
                &&$operation->started_at!==null&&is_string($operation->request_fingerprint)&&preg_match('/^[a-f0-9]{64}$/D',$operation->request_fingerprint)
                &&$operation->lease_expires_at!==null&&$retryFunding===$expectedFunding&&is_array($freshClaim)
                &&(!array_key_exists('claim',$oldFunding)||$freshClaim===$oldFunding['claim']);
            if($safeCapReset) {
                $allowed=$phase==='purchase'
                    ?['test_call_refusals','claim_key','seal','send_state','capacity_deferrals','dispatch_attempted_at','authorized_cost',$operation->action==='transfer'?'transfer_admission':'registration_admission']
                    :['test_call_refusals','claim_key','seal','send_state','capacity_deferrals'];
                $safeCapReset=($old['send_state']??null)===($phase==='purchase'?'possibly_sent':'definitely_unsent')
                    &&($phase==='purchase'?($old['seal']??null)==='sealed'&&isset($old['dispatch_attempted_at'],$old['authorized_cost'],$old[$operation->action==='transfer'?'transfer_admission':'registration_admission']):($old['seal']??null)==='absent')
                    &&($phase!=='purchase'||\Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::purchaseUnconsumed($operation->account_fingerprint,(int)$operation->id))
                    &&$validDeferrals&&!array_diff(array_keys($old),$allowed)
                    &&!array_intersect(array_keys($old),['execution','evidence','charge','order_candidates','provider_reference','registration','corrections','reconciliation','terminal']);
            }
            $safeAttemptReset=$safeCapReset||$safeTestRetry;
            if($newRefusals!==$oldRefusals&&!$safeCapReset) { throw new \DomainException('TEST call refusal history requires a proven-unsent cap reset.'); }
            if (!array_key_exists('registration_admission', $old) && array_key_exists('registration_admission', $new)
                && (($old['seal'] ?? null) !== 'absent' || ($new['seal'] ?? null) !== 'sealed' || $operation->action !== 'register')) {
                throw new \DomainException('Registration admission can only be recorded at the original seal.');
            }
            if (!array_key_exists('transfer_admission', $old) && array_key_exists('transfer_admission', $new)
                && (($old['seal'] ?? null) !== 'absent' || ($new['seal'] ?? null) !== 'sealed' || $operation->action !== 'transfer')) { throw new \DomainException('Transfer admission requires the original seal.'); }
            foreach (['converted_completion', 'legacy_retained', 'transfer_admission', 'transfer_terminal', 'claim_key', 'dispatch_attempted_at', 'authorized_cost', 'renewal_mode', 'renewal', 'registration_admission', 'execution', 'evidence', 'charge', 'order_candidates', 'registration', 'corrections', 'reconciliation', 'terminal', 'transfer_timeout'] as $key) {
                if (!$safeAttemptReset&&array_key_exists($key, $old) && (!array_key_exists($key, $new) || $new[$key] !== $old[$key])) {
                    throw new \DomainException('Attempt evidence is write-once.');
                }
            }
            if (!$safeAttemptReset&&isset($old['claim_key']) && $operation->isDirty(['started_at', 'request_fingerprint'])) { throw new \DomainException('Original attempt identity is frozen.'); }
            if (isset($old['reconciliation']) && $operation->isDirty(['provider_order_id', 'confirmed_expires_at', 'completed_at'])) { throw new \DomainException('Completion evidence is frozen.'); }
            if (($old['seal'] ?? null) === 'sealed' && $operation->isDirty('payload')) {
                throw new \DomainException('Sealed desired state is frozen.');
            }
            foreach (['claim', 'seal', 'refund_authorization', 'refund'] as $key) {
                $before = $operation->getOriginal('funding') ?? []; $after = $operation->funding ?? [];
                if (!$safeAttemptReset&&array_key_exists($key, $before) && (!array_key_exists($key, $after) || $after[$key] !== $before[$key])) {
                    throw new \DomainException('Claim funding evidence is frozen.');
                }
            }
            foreach (['auto_pay', 'auto_pay_result'] as $key) {
                $before = $operation->getOriginal('funding') ?? []; $after = $operation->funding ?? [];
                if (array_key_exists($key, $before) && (!array_key_exists($key, $after) || $after[$key] !== $before[$key])) {
                    throw new \DomainException('Auto Pay evidence is write-once.');
                }
            }
            $history = $operation->getOriginal('funding')['financial_adjustments'] ?? [];
            $next = $operation->funding['financial_adjustments'] ?? [];
            if (!is_array($history) || !array_is_list($history) || !is_array($next) || !array_is_list($next)
                || count($next) > 20 || count($next) < count($history) || array_slice($next, 0, count($history)) !== $history) {
                throw new \DomainException('Financial adjustments are append-only.');
            }
            if (!$safeAttemptReset&&($old['seal'] ?? null) === 'sealed' && ($new['seal'] ?? null) !== 'sealed') {
                throw new \DomainException('Dispatch seals cannot be removed.');
            }
        });
        static::deleting(static function (): never { throw new \DomainException('Operation ledger rows are retained.'); });
    }

    public function domain() { return $this->belongsTo(Domain::class); }
}
