<?php
// Enom 444f31c/d1392a8 Refunds, stricter retained evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Local negative proof only. Missing/null financial or provider evidence is never silently discarded. */
final class RefundProof
{
    public static function requireUnsent(Operation $op, Domain $d): void
    {
        $a = $op->attempt ?? []; $f = $op->funding ?? []; $c = $op->cost ?? [];
        if (!in_array($op->action, ['register', 'renew', 'transfer', 'privacy_purchase', 'privacy_renew'], true) || $op->domain_id !== $d->id || $op->service_id !== $d->service_id
            || $op->server_id !== $d->server_id || $op->registrar_key !== $d->registrar_key || $op->account_fingerprint !== $d->account_fingerprint
            || $op->authority_generation < 1 || $op->authority_generation !== $d->authority_generation
            || $op->provider_order_id !== null || $op->confirmed_expires_at !== null || $op->lease_expires_at !== null
            || array_diff(array_keys($c), ['estimated', 'currency', 'unit'])
            || array_diff(array_keys($f), ['claim', 'seal', 'refund_authorization', 'refund', 'financial_adjustments'])
            || array_key_exists('dispatch_attempted_at', $op->policy ?? [])) { throw new \DomainException('Refund lacks definitely-unsent proof.'); }
        if (in_array($op->action, ['register', 'transfer'], true) && ($d->provider_domain_id !== null || $d->provider_order_id !== null || $d->provider_expires_at !== null
            || !in_array($d->status, $op->action === 'transfer' ? ['transfer_pending', 'transfer_rejected'] : ['registration_pending', 'registration_rejected'], true))) { throw new \DomainException('Acquisition already has provider evidence.'); }
        if (array_key_exists('financial_adjustments', $f) && (!is_array($f['financial_adjustments']) || !array_is_list($f['financial_adjustments']))) {
            throw new \DomainException('Unknown adjustment evidence.');
        }
        if ($op->action === 'transfer' && array_key_exists('transfer_timeout', $a)) {
            if (!self::transferTimeout($op, $d)) { throw new \DomainException('Transfer is not a definitely-unsent timeout.'); }
            return;
        }
        if ($a === []) {
            if ($op->started_at !== null || $op->request_fingerprint !== null || array_key_exists('claim', $f) || array_key_exists('seal', $f)) { throw new \DomainException('Unknown attempt identity.'); }
            return;
        }
        $sealed = ($a['seal'] ?? null) === 'sealed';
        $allowed = $sealed ? ['claim_key', 'seal', 'dispatch_attempted_at', 'send_state', 'authorized_cost', 'renewal_mode', 'execution']
            : ['claim_key', 'seal', 'send_state', 'capacity_deferrals', 'disposition', 'rejection_basis', 'terminal'];
        if(!$sealed&&in_array($op->action,['privacy_purchase','privacy_renew'],true)&&array_key_exists('management_claim',$a)){
            $allowed[]='management_claim';$allowed[]='management_deferrals';if(!ManagementEvidence::deferrals($a)){throw new \DomainException('Unknown management deferral.');}
            if(!is_string($a['management_claim'])||!preg_match('/^[a-f0-9]{32}$/D',$a['management_claim'])||!$op->started_at||$op->request_fingerprint!==null
                ||!in_array($op->safe_code,['management_unconfirmed','refund_approved'],true)){throw new \DomainException('Unknown privacy claim.');}
        }
        if(isset($a['capacity_deferrals'])&&(!is_array($a['capacity_deferrals'])||!array_is_list($a['capacity_deferrals'])||count($a['capacity_deferrals'])>6||array_filter($a['capacity_deferrals'],fn($v)=>!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$v)))){throw new \DomainException('Unknown capacity deferral.');}
        if (array_diff(array_keys($a), $allowed)) { throw new \DomainException('Unresolved attempt facts prevent refund.'); }
        if ($sealed) {
            // Slice25 commits possibly_sent at seal; ONLY its exact typed local prewire result can override it.
            if (!in_array($op->safe_code, ['renewal_definitely_unsent', 'refund_approved'], true) || $op->action !== 'renew' || ($a['send_state'] ?? null) !== 'possibly_sent'
                || ($a['execution'] ?? null) !== ['disposition' => 'rejected', 'send_state' => 'definitely_unsent', 'reason' => 'unsent', 'discrepancies' => []]
                || !is_string($a['claim_key'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $a['claim_key'])
                || !$op->started_at || !$op->request_fingerprint || !isset($a['dispatch_attempted_at'], $a['authorized_cost'], $a['renewal_mode'])
                || !in_array($a['renewal_mode'], ['ordinary', 'grace'], true) || !isset($f['claim'], $f['seal']) || $f['claim'] !== $f['seal']) {
                throw new \DomainException('Sealed attempt is not proven prewire refusal.');
            }
        } elseif (($a['seal'] ?? null) !== 'absent' || ($a['send_state'] ?? null) !== 'definitely_unsent'
            || (array_key_exists('disposition', $a) && $a['disposition'] !== 'rejected')
            || (array_key_exists('rejection_basis', $a) && $a['rejection_basis'] !== 'definitely_unsent')
            || (isset($a['claim_key']) && (!$op->started_at || !$op->request_fingerprint))) {
            throw new \DomainException('Unsealed attempt is not proven unsent.');
        }
        if (!$sealed && (array_key_exists('seal', $f) || (array_key_exists('claim', $f) && (!isset($a['claim_key']) || !is_array($f['claim']))))) {
            throw new \DomainException('Unsealed financial seal or unknown claim prevents refund.');
        }
        if (!$sealed && array_key_exists('claim_key', $a) && (!is_string($a['claim_key']) || !preg_match('/^[a-f0-9]{32}$/D', $a['claim_key'])
            || !in_array($op->safe_code, ['pre_send_interrupted', 'seal_authority_invalid', 'nameservers_required', 'write_rate_limited', 'refund_approved'], true))) {
            throw new \DomainException('Unresolved local claim.');
        }
        if (array_key_exists('terminal', $a) && (($a['terminal']['operation_key'] ?? null) !== $op->operation_key
            || ($a['terminal']['generation'] ?? null) !== $op->authority_generation)) { throw new \DomainException('Foreign terminal proof.'); }
    }
    public static function transferTimeout(Operation $op, Domain $d): bool
    {
        $a = $op->attempt ?? []; $at = $op->completed_at;
        $deadline = $at ? TransferInputs::deadline($op, $at->toDateTimeImmutable()) : null;
        return $op->action === 'transfer' && $op->state === 'rejected' && $op->safe_code === 'transfer_code_timeout'
            && $op->term === 1 && $at !== null && $deadline !== null && $at >= $deadline
            && $op->started_at === null && $op->request_fingerprint === null
            && !array_intersect(['claim', 'seal'], array_keys($op->funding ?? []))
            && (!array_key_exists('epp', $op->payload ?? []) || $op->payload['epp'] === null)
            && $a === ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent',
                'transfer_timeout' => ['operation_key' => $op->operation_key, 'generation' => $d->authority_generation, 'deadline' => $deadline->format(DATE_ATOM)]];
    }
    public static function retired(Operation $op, Domain $d): bool
    {
        return $op->action === 'renew' && $op->safe_code === 'renewal_cancelled_unpaid' && OperationTerminalProof::safe($op, $d)
            && ($op->attempt['terminal'] ?? null) === ['operation_key' => $op->operation_key, 'generation' => $op->authority_generation,
                'invoice_id' => $op->invoice_id, 'transactions' => []];
    }
}
