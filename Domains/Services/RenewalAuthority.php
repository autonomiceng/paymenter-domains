<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, Money, RenewalCode, RenewalRequest};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Owns the renewal-specific ordered prefix. No general callback or write-authority scope is exposed. */
final readonly class RenewalAuthority
{
    public function __construct(private AccountBindings $bindings, private Clock $clock) {}
    public function capture(RenewalRequest $request): array { return $this->locked($request); }
    public function recheck(RenewalRequest $request, string $stamp, RenewalAcceptance $accepted, \DateTimeImmutable $until): void
    {
        $this->locked($request, $stamp, $accepted, $until);
    }
    private function locked(RenewalRequest $request, ?string $stamp = null, ?RenewalAcceptance $accepted = null, ?\DateTimeImmutable $until = null): array
    {
        OperationClaims::outside();
        try {
            $seed = Domain::findOrFail($request->domainId);
            return DB::transaction(function () use ($seed, $request, $stamp, $accepted, $until): array {
                $l = HoldingLocks::take($seed); $domain = $l['domain'];
                $binding = CatalogueAcceptances::binding($l['account']);
                try {
                    $this->bindings->requireProcessingLocked($l['server'], $l['account']); $accepted?->requireCurrent($binding);
                    // HoldingLocks already locked all server settings before catalogue/domain rows.
                    $catalogue = CatalogueAcceptances::load($binding, true);
                    $parts = CanonicalName::parse($domain->domain, array_keys($catalogue->endings));
                    $row = $catalogue->endings[$parts['tld']];
                    if (($row['renewal_safe'] ?? null) !== true
                        || !in_array(1, $row['renew'] ?? [], true)) { throw new RenewalRefused(RenewalCode::Acceptance); }
                }
                catch (\Throwable) { throw new RenewalRefused(RenewalCode::Acceptance); }
                // A named issued obligation keeps its original authority through a future-only stop.
                $issued=$l['operations']->contains(fn($op)=>self::expected($op,$request));
                if (!in_array($domain->status, ['active', 'expired', 'grace'], true) || $l['service']->status !== 'active'
                    || ($issued?RenewalStop::blocksExisting($l):RenewalStop::stopped($l))) { throw new RenewalRefused(RenewalCode::Authority); }
                $all = $l['operations']; $prices = $l['prices'];
                if ($domain->authority_generation !== $request->generation || $domain->confirmed_revision !== $request->revision
                    || $domain->provider_expires_at?->format('Y-m-d') !== $request->expiry || $domain->provider_domain_id !== $request->providerId
                    || ($binding->registrarKey === 'enom' && !preg_match('/^[1-9][0-9]{0,19}$/D', $request->providerId ?? ''))) {
                    throw new RenewalRefused(RenewalCode::Anchor);
                }
                if ($domain->renewal_policy['automatic_spend_paused'] ?? false) { throw new RenewalRefused(RenewalCode::Paused); }
                try { $anchor = HoldingAnchor::resolve($l); }
                catch (RenewalRefused $error) { throw $error; }
                catch (\Throwable) { throw new RenewalRefused(RenewalCode::Prior); }
                $prior = $anchor->operation;
                if ($all->contains(fn ($op) => $op->id !== $prior?->id && !HoldingAnchor::resolved($op, $l) && !self::expected($op, $request))) {
                    throw new RenewalRefused(RenewalCode::Prior);
                }
                if ($prior) { self::requireCostAnchor($prior); }
                $current = $l['stamp'];
                if (($stamp !== null && $stamp !== $current) || ($until !== null && $until <= $this->clock->now())) {
                    throw new RenewalRefused(RenewalCode::Changed);
                }
                $annual = $prices->where('term', 1)->where('currency', 'USD')->where('account_fingerprint', $binding->fingerprint)->first();
                $retail = $annual?->retail !== null ? PricingRule::total(PriceCatalogue::retail($annual->retail), $request->years) : null;
                // Resolve original registration proof after the latest anchor advances to a renewal.
                $registration = $l['identity_source']['kind'] !== 'checkout' ? null : $all->first(fn ($op) => in_array($op->action, ['register', 'transfer'], true) && $op->state === 'succeeded' && self::resolved($op, $domain, $binding));
                $prior = $registration ?? $prior;
                $admission = $prior?->attempt[$prior?->action === 'transfer' ? 'transfer_admission' : 'registration_admission'] ?? [];
                $nonpremium = false;
                try {
                    $nonpremium = $prior !== null && ($admission['premium'] ?? null) === false && ($admission['name'] ?? null) === $domain->domain
                        && ($admission['years'] ?? null) === (int) $prior->term
                        && ($admission['cost'] ?? null) === ($prior->attempt['authorized_cost'] ?? null)
                        && ($admission['cost']['currency'] ?? null) === 'USD'
                        && WriteMoney::compare((new Money($prior->cost['actual']['amount'] ?? '', 'USD'))->amount,
                            (new Money($admission['cost']['amount'] ?? '', 'USD'))->amount) === 0;
                } catch (\InvalidArgumentException | \TypeError) {}
                return [$binding, $domain->domain, $current, $retail, $nonpremium];
            });
        } catch (RenewalRefused $error) { throw $error; }
        catch (\Throwable) { throw new RenewalRefused(RenewalCode::Authority); }
    }
    public static function requireCostAnchor(Operation $op): void
    {
        if ($op->action === 'renew' && WriteMoney::compare($op->cost['actual']['amount'] ?? '', $op->attempt['authorized_cost']['amount'] ?? '') !== 0) { throw new RenewalRefused(RenewalCode::Cost); }
    }
    /** Exactly the named virgin obligation may coexist with prospective admission. */
    private static function expected(Operation $op, RenewalRequest $r): bool
    {
        return $r->obligationKey !== null && $op->operation_key === $r->obligationKey && $op->action === 'renew'
            && in_array($op->state, ['awaiting_payment', 'queued'], true) && ($op->attempt ?? []) === []
            && RenewalCycles::virgin($op) && $op->provider_order_id === null
            && $op->confirmed_expires_at === null && $op->authority_generation === $r->generation
            && $op->base_expires_at?->format('Y-m-d') === $r->expiry && (int) $op->term === $r->years;
    }
    public static function resolved(Operation $op, Domain $domain, \Paymenter\Extensions\Servers\Domains\Data\AccountBinding $binding): bool
    {
        if (RenewalReplacements::reviewed($op,$domain) || ManagementEvidence::resolved($op, $domain) || OperationTerminalProof::safe($op, $domain)) { return true; }
        if (!$op->confirmed_expires_at || $op->confirmed_expires_at > $domain->provider_expires_at) { return false; }
        $historical = clone $domain; $historical->provider_expires_at = $op->confirmed_expires_at;
        return self::completed($op, $historical, $binding);
    }
    /** Retained completion proof only; reusable by invoice creation and grants no execution authority. */
    public static function completed(Operation $op, Domain $domain, \Paymenter\Extensions\Servers\Domains\Data\AccountBinding $binding): bool
    {
        if ($domain->adoptionPending()) { return false; }
        if (ConvertedHoldingProof::completed($op,$domain,$binding)) { return true; }
        $p = $op->attempt['reconciliation'] ?? [];
        try { if ($op->action === 'transfer' && (($p['transfer_status'] ?? null) !== 'completed'
            || !($base = $op->attempt['transfer_admission']['base_expiry'] ?? null) || ($p['base_expiry'] ?? null) !== $base
            || \Carbon\CarbonImmutable::parse($base)->addYearsNoOverflow($op->term)->format('Y-m-d') !== ($p['expiry'] ?? null))) { return false; } } catch (\Throwable) { return false; }
        if ($op->state !== 'succeeded' || !in_array($op->action, ['register', 'renew', 'transfer'], true) || $op->service_id !== $domain->service_id
            || $op->server_id !== $domain->server_id || $op->registrar_key !== $domain->registrar_key
            || $op->account_fingerprint !== $binding->fingerprint || $op->authority_generation !== $domain->authority_generation
            || $op->confirmed_expires_at?->format('Y-m-d') !== $domain->provider_expires_at?->format('Y-m-d')
            || ($p['expiry'] ?? null) !== $domain->provider_expires_at?->format('Y-m-d')
            || ($p['provider_domain_id'] ?? null) !== $domain->provider_domain_id || ($p['fingerprint'] ?? null) !== $binding->fingerprint
            || ($p['generation'] ?? null) !== $domain->authority_generation || ($p['operation_key'] ?? null) !== $op->operation_key
            || ($p['charge'] ?? null) !== ($op->cost['actual'] ?? null) || ($p['charge']['currency'] ?? null) !== 'USD'
            || ($p['query'] ?? null) !== ['name' => $domain->domain, 'action' => $op->action, 'years' => (int) $op->term]
            || !in_array($p['source'] ?? null, ['captured_order', 'accepted_history'], true)) { return false; }
        // Reuse the original sealed-attempt/PersistedExecution reconstruction, never a state-label shortcut.
        try {
            // A history-discovered ID is completion proof, not a retroactive original response ID.
            $original = clone $op;
            $reference = $op->attempt[$op->action === 'renew' ? 'renewal' : 'registration']['order_reference'] ?? $op->attempt['evidence']['registration']['orderReference'] ?? null;
            if ($p['source'] === 'accepted_history' && $reference === null) { $original->provider_order_id = null; }
            $attempt = ReconciliationEvidence::attempt($original, $domain, $binding);
            $processed = new \DateTimeImmutable($p['processed_at'] ?? 'invalid');
            if (($p['window_start'] ?? null) !== $attempt->windowStart->format(DATE_ATOM)
                || ($p['window_end'] ?? null) !== $attempt->windowEnd->format(DATE_ATOM)
                || $processed < $attempt->windowStart || $processed > $attempt->windowEnd) { return false; }
            if ($p['source'] === 'captured_order') {
                if (!$attempt->executionFacts) { return false; }
                $attempt->executionFacts->requireAttempt($attempt);
            }
            return ($p['order_reference'] ?? null) === $op->provider_order_id;
        } catch (\Throwable) { return false; }
    }
}
