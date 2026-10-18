<?php
// Enom 444f31c acquisition/follow-up semantics; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{Disposition, DriverResult};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Called only by the matching live worker under domain -> operations locks. No evidence interpretation/replay. */
final class RegistrationCompletion
{
    public static function record(Operation $op, Domain $domain, DriverResult $result): ?array
    {
        $facts = $result->evidence()->registration;
        if (!$facts || !in_array($result->disposition, [Disposition::Accepted, Disposition::AcceptedWithDiscrepancy], true)) { return null; }
        try { $current = $op->action === 'register' && OperationClaims::query($op, $domain)->matches($facts->query)
            && $op->account_fingerprint === $result->binding->fingerprint && $domain->account_fingerprint === $op->account_fingerprint
            && $domain->authority_generation === $op->authority_generation && $domain->reservation_name === $domain->domain;
        } catch (\Throwable) { $current = false; }
        // Persist correction work atomically with acceptance. It has no implicit write authorization.
        $attempt = $op->attempt;
        $attempt['registration'] = ['accepted' => true, 'query' => ['name' => $facts->query->name, 'years' => $facts->query->years],
            'order_reference' => $facts->orderReference, 'response_expiry' => $facts->responseExpiry?->value,
            'financial_completion' => 'awaiting_unique_charge'];
        $attempt['corrections'] = array_fill_keys($facts->corrections, ['state' => 'authorization_required', 'readback_required' => true]);
        // No owned/expiry/billing anchor until22 accepts original-attempt charge/term/ownership proof.
        return ['attempt' => $attempt, 'state' => $current ? 'pending' : 'uncertain',
            'safe_code' => !$current ? 'registration_authority_changed' : ($facts->corrections ? 'registration_correction_required' : 'registration_evidence_required')];
    }
}
