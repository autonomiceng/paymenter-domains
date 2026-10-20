<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain};

/** Derives provenance from retained locked rows, never a caller-provided identity. */
final class HoldingIdentitySource
{
    public static function resolve(Domain $d, array $native, ?Adoption $a, $account, ?\Illuminate\Support\Collection $cancel = null): array
    {
        try { $snapshot = $d->snapshot ?? []; $e = $a?->evidence; }
        catch (\Throwable) { throw new \DomainException('Adoption evidence unavailable; staff review required.'); }
        if ($d->adoption_id === null && !isset($snapshot['holding']) && $d->provenance !== 'adopted') {
            $c = $snapshot['checkout'] ?? [];
            foreach (['user_id', 'service_id', 'order_id', 'product_id', 'annual_plan_id'] as $key) {
                if (($c[$key] ?? null) !== $native[$key]) { throw new \DomainException('Holding checkout identity changed.'); }
            }
            if (($c['name'] ?? null) !== $d->domain || ($c['action'] ?? null) !== $native['route_action']) {
                throw new \DomainException('Holding checkout route changed.');
            }
            return ['kind' => 'checkout', 'native_identity' => $native];
        }
        $h = $snapshot['holding'] ?? null;
        if (!$a || $d->adoption_id !== (int) $a->id || (int) $a->domain_id !== (int) $d->id
            || $a->state !== 'committed' || !$a->committed_at || $d->provenance !== 'adopted' || isset($snapshot['checkout'])
            || (int) $a->service_id !== (int) $d->service_id || (int) $a->user_id !== (int) $d->user_id
            || (int) $a->server_id !== (int) $d->server_id || $a->registrar_key !== $d->registrar_key
            || $a->account_fingerprint !== $d->account_fingerprint || $a->account_fingerprint !== $account->account_fingerprint
            || $a->environment !== 'live' || $account->environment !== 'live' || $a->environment !== ($e['environment'] ?? null)
            || ($e['do_not_renew'] ?? null) !== $a->do_not_renew
            || ($a->do_not_renew ? !$cancel?->contains(fn ($c) => $c instanceof \App\Models\ServiceCancellation
                && (int) $c->id === (int) $a->cancellation_id && (int) $c->service_id === (int) $d->service_id && $c->type === 'end_of_period') : $a->cancellation_id !== null)
            || $a->authority_generation !== $d->authority_generation || $d->authority_generation < 1
            || !is_array($h) || ($e['version'] ?? null) !== 1 || ($e['canonical_name'] ?? null) !== $d->domain
            || ($e['registrar_key'] ?? null) !== $d->registrar_key || ($e['account_proof_digest'] ?? null) !== $d->account_fingerprint
            || ($e['native_identity'] ?? null) !== $native || $native['route_action'] !== 'register'
            || ($e['resulting_generation'] ?? null) !== $d->authority_generation
            || ($e['prior_generation'] ?? null) !== $d->authority_generation - 1
            || !is_int($e['resulting_revision'] ?? null) || $e['resulting_revision'] < 1
            || ($e['prior_revision'] ?? null) !== $e['resulting_revision'] - 1
            || ($e['auto_renew'] ?? null) !== false || ($e['ordinary'] ?? null) !== true
            || ($e['hold'] ?? null) !== false || ($e['redemption'] ?? null) !== false || (!array_key_exists('refusal', $e) || $e['refusal'] !== null)
            || !hash_equals($a->request_digest, AdoptionMapping::stamp($e))
            || !is_string($e['expiry']['value'] ?? null)) { throw new \DomainException('Adoption authority requires staff review.'); }
        $expiry = substr($e['expiry']['value'], 0, 10);
        new \Paymenter\Extensions\Servers\Domains\Data\DateEvidence($expiry, \Paymenter\Extensions\Servers\Domains\Data\ExpiryPrecision::Date, 'adoption');
        $expected = ['version' => 1, 'adoption_id' => (int) $a->id, 'request_digest' => $a->request_digest,
            'name' => $d->domain, 'native_identity' => $native, 'registrar_key' => $a->registrar_key,
            'environment' => $a->environment, 'account_fingerprint' => $a->account_fingerprint,
            'generation' => $a->authority_generation, 'confirmed_revision' => $e['resulting_revision'],
            'expiry' => $expiry, 'provider_domain_id' => $e['provider_domain_id'] ?? null];
        if ($h !== $expected || $d->provider_domain_id !== $h['provider_domain_id']
            || ($d->registrar_key === 'enom' && !preg_match('/^[1-9][0-9]{0,19}$/D', $d->provider_domain_id ?? ''))) {
            throw new \DomainException('Original adoption identity requires staff review.');
        }
        return ['kind' => 'adoption', 'do_not_renew' => $a->do_not_renew, 'native_identity' => $native, 'holding' => $h, 'prior_operations_stamp' => $e['revisions']['operations'] ?? null];
    }
}
