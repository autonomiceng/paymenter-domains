<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Terminal-history policy adapted from Enom d2c55e2 Services/DomainReservations.php.
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};

final class DomainReservations
{
    /** Caller holds policy/catalogue locks; outer native checkout owns all invoice/service writes. */
    public static function reserve(array $attributes, array $allowedTlds): Domain
    {
        $name = CanonicalName::parse($attributes['domain'] ?? '', $allowedTlds);
        return DB::transaction(static function () use ($attributes, $name): Domain {
            self::releaseLocked($name['domain']);
            // The database's global unique key arbitrates absent-row races across all sellers.
            return Domain::create(array_replace($attributes, $name, ['reservation_name' => $name['domain']]));
        });
    }

    public static function releaseTerminal(string $input): void
    {
        $name = CanonicalName::normalize($input);
        DB::transaction(static fn () => self::releaseLocked($name));
    }

    private static function releaseLocked(string $name): void
    {
        $domain = Domain::where('reservation_name', $name)->lockForUpdate()->first();
        if (!$domain) { return; }
        $operations = $domain->operations()->orderBy('id')->lockForUpdate()->get();
        if (!in_array($domain->status, ['registration_rejected', 'transfer_rejected', 'cancelled'], true)
            || $domain->provider_expires_at !== null || $domain->provider_domain_id !== null || $domain->provider_order_id !== null
            || $operations->whereIn('action', ['register', 'transfer'])->isEmpty()
            || $operations->contains(static fn (Operation $op): bool => !self::resolved($op, $domain))) {
            throw new \DomainException('This domain already has an order.');
        }
        // Single release writer; ordinary model edits cannot clear a reservation.
        Domain::whereKey($domain->id)->update(['reservation_name' => null]);
    }

    private static function resolved(Operation $operation, Domain $domain): bool
    {
        if ((int) $operation->authority_generation < 1 || $operation->account_fingerprint === null
            || $operation->state !== 'rejected' || $operation->confirmed_expires_at !== null
            || (int) $operation->service_id !== (int) $domain->service_id
            || (int) $operation->server_id !== (int) $domain->server_id
            || $operation->registrar_key !== $domain->registrar_key
            || $operation->account_fingerprint !== $domain->account_fingerprint
            || (int) $operation->authority_generation !== (int) $domain->authority_generation) { return false; }
        $attempt = $operation->attempt ?? [];
        // Missing evidence is not proof. The engine records this normalized, encrypted envelope.
        if (($attempt['disposition'] ?? null) !== 'rejected' || ($attempt['charge'] ?? null) !== null
            || ($attempt['order_candidates'] ?? []) !== [] || array_key_exists('actual', $operation->cost ?? [])) { return false; }
        if (($attempt['rejection_basis'] ?? null) === 'provider_proof') {
            return $domain->status !== 'cancelled' && ($attempt['send_state'] ?? null) === 'sent'
                && ($attempt['charge_absent'] ?? null) === true && ($attempt['charge'] ?? null) === null
                && ($attempt['order_candidates'] ?? []) === [] && !array_key_exists('actual', $operation->cost ?? []);
        }
        return ($attempt['rejection_basis'] ?? null) === 'definitely_unsent'
            && ($attempt['send_state'] ?? null) === 'definitely_unsent'
            && ($attempt['seal'] ?? null) === 'absent' && !array_key_exists('execution', $attempt) && !array_key_exists('dispatch_attempted_at', $attempt)
            && !array_key_exists('provider_reference', $attempt) && $operation->provider_order_id === null
            && !array_key_exists('execution', $operation->cost ?? [])
            && !array_key_exists('dispatch_attempted_at', $operation->policy ?? []);
    }
}
