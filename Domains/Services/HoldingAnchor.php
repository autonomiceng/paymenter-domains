<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Operation;

/** Renewal-only union. Original adoption remains the root after completed renewals. */
final readonly class HoldingAnchor
{
    private function __construct(public string $kind, public ?Operation $operation) {}
    /** A previous generation is accepted only as unchanged, proved-unsent adoption history. */
    public static function resolved(Operation $op, array $l): bool
    {
        $d = $l['domain'];
        if (RenewalAuthority::resolved($op, $d, CatalogueAcceptances::binding($l['account']))) { return true; }
        $source = $l['identity_source'];
        if ($source['kind'] !== 'adoption' || $op->authority_generation !== $source['holding']['generation'] - 1) { return false; }
        $history = $l['operations']->filter(fn ($row) => $row->authority_generation === $source['holding']['generation'] - 1)->values();
        $stamp = $source['prior_operations_stamp'] ?? null;
        if (!is_string($stamp) || !hash_equals($stamp, AdoptionMapping::stamp($history->map(fn ($row) => $row->getRawOriginal())->all()))) { return false; }
        $previous = clone $d; $previous->authority_generation--;
        return $history->every(fn ($row) => OperationTerminalProof::safe($row, $previous));
    }
    public static function resolve(array $l): self
    {
        $d = $l['domain']; $a = $d->renewal_policy['billing_anchor'] ?? [];
        $binding = CatalogueAcceptances::binding($l['account']);
        $prior = $l['operations']->firstWhere('operation_key', $a['operation_key'] ?? null);
        $source = $l['identity_source'];
        if (($a['expiry'] ?? null) !== $d->provider_expires_at?->format('Y-m-d')) { throw new \DomainException('Confirmed renewal anchor requires review.'); }
        if ($source['kind'] === 'adoption') {
            $h = $source['holding']; $revision = $h['confirmed_revision']; $expiry = $h['expiry']; $last = null;
            foreach ($l['operations'] as $op) {
                if ($op->action !== 'renew' || $op->state !== 'succeeded') { continue; }
                $historical = clone $d; $historical->provider_expires_at = $op->confirmed_expires_at;
                if ($op->base_expires_at?->format('Y-m-d') !== $expiry
                    || $op->base_expires_at?->addYearsNoOverflow((int) $op->term)->format('Y-m-d') !== $op->confirmed_expires_at?->format('Y-m-d') || ($op->policy['renewal']['confirmed_revision'] ?? null) !== $revision
                    || $op->expected_expires_at?->format('Y-m-d') !== $op->confirmed_expires_at?->format('Y-m-d')
                    || !RenewalAuthority::completed($op, $historical, $binding)) { throw new \DomainException('Adoption renewal revision chain requires review.'); }
                RenewalAuthority::requireCostAnchor($op);
                $expiry = $op->confirmed_expires_at->format('Y-m-d'); $revision++; $last = $op;
            }
            if ($d->confirmed_revision !== $revision || $d->provider_expires_at?->format('Y-m-d') !== $expiry
                || ($a['confirmed_revision'] ?? null) !== $revision) { throw new \DomainException('Adoption current revision requires review.'); }
            if (!$last) {
                if ($a !== ['kind' => 'adoption', 'adoption_id' => $d->adoption_id, 'expiry' => $expiry, 'confirmed_revision' => $revision]) {
                    throw new \DomainException('Original adoption anchor requires review.');
                }
                return new self('adoption', null);
            }
            if (($a['kind'] ?? null) !== 'completed_operation' || $prior?->id !== $last->id) { throw new \DomainException('Latest adopted renewal anchor requires review.'); }
        }
        if (!$prior || !RenewalAuthority::completed($prior, $d, $binding)) { throw new \DomainException('Completed renewal anchor requires review.'); }
        return new self('completed_operation', $prior);
    }
}
