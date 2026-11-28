<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Operation,ProfileContactOperation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AcquisitionDispatch, AcquisitionRetryProof};
/** A paid NameSilo order held at its contact step with nothing sent: the purchase was never sealed and the order's own
 * contact creation is still waiting or proved unsent. Callers hold the operation locks; the contact row is read under them. */
final class ContactProfileHold
{
    public const CODE='contact_profile_proof_required';
    /** Holds an order can reach at its contact step with nothing sent: the contact hold, or the real local cause. */
    public const HOLDS=[self::CODE,'processing','write_rate_limited'];
    public const RECOVERY_HOLDS = [
        self::CODE, 'write_rate_limited', 'processing', 'namesilo_account_guard', 'write_acceptance_required',
        'pre_send_interrupted', 'registrar_read_failed', 'registrar_price_missing',
    ];
    public const RETRY_BLOCKED='The contact step cannot be retried with these order details. Cancel and refund this order.';
    public const RETRY_ENDED='This contact step has ended and cannot be retried. Cancel and refund this order.';
    /** A saved step cannot be reopened or send different details. The offer and writer give the same cause. */
    public static function releaseBlocked(Operation $op): ?string
    {
        if(self::unsent($op,retry:true)){
            $environment=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->value('environment');
            $test=$environment==='test';
            if(config('domains.namesilo_'.($test?'test':'live').'.management',$test)!==true){
                return 'Contact creation is off on this site; the site administrator must enable NameSilo contact creation before this order can retry.';
            }
        }
        if(!self::unsent($op)||self::unsent($op,retry:true)){return null;}
        $matches=ProfileContactOperation::where('operation_id',$op->id)->value('payload_digest')===OperationClaims::payloadDigest($op);
        return $matches?self::RETRY_ENDED:self::RETRY_BLOCKED;
    }
    public static function forReadmission(Operation $op, \DateTimeImmutable $now, bool $lock = false): ?ProfileContactOperation
    {
        if (!AcquisitionRetryProof::recoverableClaim($op, $now)) { return null; }
        if ($op->state === 'held' && !in_array($op->safe_code, self::RECOVERY_HOLDS, true)) { return null; }
        if ($op->state === 'held' && $op->safe_code === 'registrar_read_failed'
            && ($op->policy['read_retries'] ?? 0) >= BalanceRetries::READ_LIMIT) { return null; }
        return self::savedForRecovery($op, $lock);
    }

    /** A replacement may read the saved ID through the existing contact sender. */
    public static function savedForRecovery(Operation $op, bool $lock = false): ?ProfileContactOperation
    {
        if ($op->registrar_key !== 'namesilo' || AcquisitionAttempt::key($op->action) === null || $op->completed_at !== null) { return null; }
        try {
            if (!AcquisitionRetryProof::unsealed($op) || ($op->attempt['send_state'] ?? null) !== 'definitely_unsent'
                || !is_array($op->funding['claim'] ?? null) || !FinancialAdjustments::registrarUnattemptedFunding($op, allowClaim: true)) { return null; }
            AcquisitionDispatch::requireNoSendEvidence($op);
            $row = self::row($op, $lock);
            return $row && self::bound($row, $op) && self::saved($row) ? $row : null;
        } catch (\Illuminate\Database\QueryException|\Illuminate\Contracts\Encryption\DecryptException|\JsonException|\DomainException) { return null; }
    }

    /** A purchase disposition must account for the order's contact evidence when a row exists. */
    public static function allowsPurchaseDisposition(Operation $op, bool $lock = false, bool $requirePayload = true, bool $requireRow = false): bool
    {
        if ($op->registrar_key !== 'namesilo') { return true; }
        try {
            $row = self::row($op, $lock);
            if (!$row) { return !$requireRow; }
            $verified = $row->state === 'verified';
            if (!self::bound($row, $op, requirePayload: $requirePayload || $verified)) { return false; }
            $a = $row->attempt;
            return ($verified && self::saved($row) && isset($a['profile_id'], $a['observed_at']))
                || ($row->state === 'ready' && ($a['seal'] ?? null) === 'absent'
                    && ($a['send_state'] ?? null) === 'definitely_unsent' && !isset($a['worker_key']) && !isset($a['dispatch_at']) && !isset($a['provider_id']))
                || ($row->state === 'unsent' && ($a['execution'] ?? null) === 'definitely_unsent'
                    && !isset($a['dispatch_at']) && !isset($a['provider_id']));
        } catch (\Illuminate\Database\QueryException|\Illuminate\Contracts\Encryption\DecryptException|\JsonException|\DomainException) { return false; }
    }

    private static function row(Operation $op, bool $lock): ?ProfileContactOperation
    {
        return ProfileContactOperation::where('operation_id', $op->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    private static function bound(ProfileContactOperation $row, Operation $op, bool $requirePayload = true): bool
    {
        $domain = $op->domain;
        $parent = $row->attempt['parent_claim'] ?? null;
        return is_string($parent) && preg_match('/^[a-f0-9]{32}$/D', $parent) && $domain && $row->user_id === (int) $domain->user_id && $row->server_id === $op->server_id
            && $row->account_fingerprint === $op->account_fingerprint && $row->authority_generation === $op->authority_generation
            && $domain->authority_generation === $op->authority_generation
            && (!$requirePayload || $row->payload_digest === OperationClaims::payloadDigest($op))
            && $parent === ($op->attempt['claim_key'] ?? null);
    }

    private static function saved(ProfileContactOperation $row): bool
    {
        $a = $row->attempt;
        return in_array($row->state, ['created', 'verified'], true) && ($a['seal'] ?? null) === 'sealed'
            && ($a['send_state'] ?? null) === 'possibly_sent' && isset($a['worker_key'], $a['dispatch_at'], $a['received_at'])
            && is_string($a['provider_id'] ?? null) && preg_match('/^[1-9][0-9]{0,19}$/D', $a['provider_id']);
    }

    /** Nothing reached NameSilo for this order. $retry also requires the contact step to be able to run again, which
     * only a waiting contact row can: a proved-unsent row stays unsent, so a release would only hold again. */
    public static function unsent(Operation $op,bool $retry=false): bool
    {
        $a=$op->attempt??[];
        if($op->registrar_key!=='namesilo'||!in_array($op->action,['register','transfer'],true)||$op->funding_basis!=='paid_invoice'
            ||$op->lease_expires_at!==null||$op->completed_at!==null||$op->provider_order_id!==null
            ||array_diff(array_keys($a),['claim_key','seal','send_state','capacity_deferrals'])||($a['seal']??null)!=='absent'
            ||($a['send_state']??null)!=='definitely_unsent'||!is_string($a['claim_key']??null)||!preg_match('/^[a-f0-9]{32}$/D',$a['claim_key'])
            ||!OperationClaims::localDeferrals($a)||array_key_exists('seal',$op->funding??[])||!is_array($op->funding['claim']??null)
            ||array_key_exists('dispatch_attempted_at',$op->policy??[])){return false;}
        // An order that never made a contact row may have used the older direct sender, which kept no record of a send.
        try{$row=self::row($op,DB::transactionLevel()>0);}
        catch(\Illuminate\Database\QueryException){return false;} // No contact ledger installed: no proof.
        $r=$row?->attempt??[];
        if(!$row||$row->authority_generation!==$op->authority_generation||$row->account_fingerprint!==$op->account_fingerprint
            ||($retry&&$row->payload_digest!==OperationClaims::payloadDigest($op))
            ||($r['parent_claim']??null)!==$a['claim_key']||isset($r['dispatch_at'])||isset($r['provider_id'])){return false;}
        if($row->state==='ready'){return ($r['seal']??null)==='absent'&&($r['send_state']??null)==='definitely_unsent'&&!isset($r['worker_key']);}
        return !$retry&&$row->state==='unsent'&&($r['execution']??null)==='definitely_unsent';
    }
}
