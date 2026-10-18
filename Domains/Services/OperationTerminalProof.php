<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** No provider observation grants a negative proof. Only a completed pre-seal worker may be encoded. */
final class OperationTerminalProof
{
    public static function encode(OperationClaims $claims, int $id): Operation
    {
        return $claims->locked($id, static function ($op, $domain, $all): Operation {
            $a = $op->attempt ?? [];
            if ($op->state !== 'held' || !in_array($op->safe_code, ['pre_send_interrupted', 'seal_authority_invalid'], true)
                || !isset($a['claim_key']) || ($a['seal'] ?? null) !== 'absent' || ($a['send_state'] ?? null) !== 'definitely_unsent'
                || array_diff(array_keys($a), ['claim_key', 'seal', 'send_state']) || $op->lease_expires_at !== null
                || $op->provider_order_id !== null || $op->confirmed_expires_at !== null || array_key_exists('actual', $op->cost ?? [])
                || array_key_exists('execution', $op->cost ?? []) || array_key_exists('refund', $op->funding ?? [])
                || array_key_exists('dispatch_attempted_at', $op->policy ?? []) || $domain->status !== 'registration_pending'
                || $domain->provider_expires_at !== null || $domain->provider_domain_id !== null || $domain->provider_order_id !== null
                || $all->contains(static fn ($other) => $other->id !== $op->id && !self::safe($other, $domain))) {
                throw new \DomainException('No definitely-unsent terminal proof.');
            }
            $op->update(['state' => 'rejected', 'attempt' => [...$a, 'terminal' => ['operation_key' => $op->operation_key, 'generation' => $op->authority_generation],
                'disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent']]);
            if (!self::safe($op, $domain)) { throw new \DomainException('Terminal identity mismatch.'); }
            $domain->update(['status' => 'registration_rejected']); return $op;
        });
    }
    public static function safe(Operation $op, Domain $domain): bool
    {
        $a = $op->attempt ?? [];
        return $op->authority_generation > 0 && is_string($op->account_fingerprint) && $op->state === 'rejected' && in_array($op->action, ['register', 'renew'], true) && $op->account_fingerprint === $domain->account_fingerprint
            && $op->registrar_key === $domain->registrar_key && $op->server_id === $domain->server_id && $op->service_id === $domain->service_id
            && $op->authority_generation === $domain->authority_generation && $op->confirmed_expires_at === null && $op->provider_order_id === null
            && (
                $op->action === 'register' || (($a['terminal']['operation_key'] ?? null) === $op->operation_key
                && ($a['terminal']['generation'] ?? null) === $op->authority_generation
                && $op->safe_code === 'renewal_cancelled_unpaid' && ($a['terminal']['invoice_id'] ?? null) === $op->invoice_id))
            && ($a['disposition'] ?? null) === 'rejected' && ($a['rejection_basis'] ?? null) === 'definitely_unsent'
            && ($a['send_state'] ?? null) === 'definitely_unsent' && ($a['seal'] ?? null) === 'absent'
            && !array_intersect(array_keys($a), ['execution', 'dispatch_attempted_at', 'provider_reference', 'evidence', 'registration'])
            && ($a['charge'] ?? null) === null && ($a['order_candidates'] ?? []) === []
            && !array_intersect(array_keys($op->cost ?? []), ['actual', 'execution']) && !array_key_exists('dispatch_attempted_at', $op->policy ?? []);
    }
}
