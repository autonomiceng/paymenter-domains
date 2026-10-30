<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Collection;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Skip only proven-unsent acquisitions. Unknown outcomes and known holdings remain readable. */
final class ObservationEligibility
{
    public static function allows(Domain $d, ?Collection $operations=null): bool
    {
        if (!in_array($d->status,['registration_pending','transfer_pending'],true)
            || $d->provider_domain_id!==null || $d->provider_order_id!==null || $d->provider_expires_at!==null) { return true; }
        $facts=$d->observation['facts']??null;
        if (is_array($facts) && ObservationProjection::matches($d,$facts) && ($facts['owned']??null)===true) { return true; }
        $operations??=$d->operations()->whereIn('action',['register','transfer'])->get();
        $acquisitions=$operations->filter(fn($op)=>in_array($op->action,['register','transfer'],true)
            && $op->authority_generation===$d->authority_generation && $op->account_fingerprint===$d->account_fingerprint);
        if ($acquisitions->isEmpty()) { return true; } // No ledger proof is not negative proof.
        foreach ($acquisitions as $op) {
            if (!in_array($op->state,['awaiting_payment','awaiting_code','queued','held','cancelled','rejected'],true)) { return true; }
            try { RefundProof::requireUnsent($op,$d); }
            catch (\DomainException) { return true; }
        }
        return false;
    }
}
