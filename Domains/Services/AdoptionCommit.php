<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, Environment};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{RefusalCode, StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain, Operation, Tld};

/** Local adoption only. No checkout, funding, provider operation or network dependency. */
final class AdoptionCommit
{
    public function __construct(private readonly AdoptionMapping $mapping, private readonly AccountBindings $bindings,
        private readonly Clock $clock) {}

    public function confirm(string $requestKey, string $expectedDigest, User $actor): Domain
    {
        OperationClaims::outside();
        if (config('domains.adoption.enabled', false) !== true) { throw new AdoptionRefused(RefusalCode::Disabled); }
        $seed = Adoption::where('request_key', $requestKey)->firstOrFail();
        $e = $seed->evidence;
        self::request($seed, $expectedDigest, (int) $actor->id);
        $target = new Target($e['canonical_name'], (int) $seed->service_id, (int) $seed->user_id,
            (int) $seed->server_id, Environment::from($seed->environment));
        $suffixes = Tld::where('server_id', $seed->server_id)->pluck('tld')->all();
        $name = CanonicalName::parse($target->name, $suffixes);
        $result = DB::transaction(function () use ($seed, $e, $target, $name, $expectedDigest, $actor): Domain {
            $lock = HoldingIdentityLock::lockForAdoption($e['native_identity']['order_id'], $target->serviceId, (int) $actor->id);
            $rows = $lock->nativeRows($target->customerId); self::permissions($rows['staff'], $e);
            $identitySeed = new Domain(['user_id' => $target->customerId, 'service_id' => $target->serviceId,
                'server_id' => $target->serverId, 'domain' => $target->name, 'tld' => $name['tld'],
                'registrar_key' => $seed->registrar_key, 'account_fingerprint' => $seed->account_fingerprint]);
            if ($lock->nativeIdentity($identitySeed) !== $e['native_identity']) { throw new AdoptionRefused(RefusalCode::VerificationChanged); }
            $extension = $lock->extensionRows();
            $this->bindings->requireReadableLocked($extension['server'], $extension['account']);
            // The account mutex serializes confirmations. Inspect the current locked result, not the discovery seed.
            $candidates = Domain::where(fn ($q) => $q->where('adoption_id', $seed->id)->orWhere('reservation_name', $target->name))->orderBy('id')->lockForUpdate()->get();
            $current = $candidates->firstWhere('adoption_id', $seed->id) ?? $candidates->first();
            if ($seed->state === 'committed' || ($current && (int) $current->adoption_id === (int) $seed->id)) {
                // Replay acquires the same prefix but never consults current retail or preview age.
                $d = $current ?? throw new AdoptionRefused(RefusalCode::ResultReview);
                Operation::where('domain_id', $d->id)->orderBy('id')->lockForUpdate()->get();
                $a = Adoption::whereKey($seed->id)->lockForUpdate()->firstOrFail();
                self::request($a, $expectedDigest, (int) $actor->id);
                if ($a->state !== 'committed' || $d->adoption_id !== (int) $a->id || (int) $a->domain_id !== (int) $d->id
                    || (int) $d->user_id !== $target->customerId || (int) $d->service_id !== $target->serviceId
                    || (int) $d->server_id !== $target->serverId || $d->account_fingerprint !== $a->account_fingerprint
                    || $d->domain !== $target->name) { throw new AdoptionRefused(RefusalCode::ResultReview); }
                return $d;
            }
            if (Domain::where('server_id', $target->serverId)->where('provider_domain_id', $e['provider_domain_id'])
                ->where('domain', '!=', $target->name)->lockForUpdate()->exists()) { throw new AdoptionRefused(RefusalCode::IdentityReview); }
            $capture = $this->mapping->capture($target, new StaffContext((int) $actor->id, $seed->reason, $e['requested_do_not_renew']), $lock);
            $a = Adoption::whereKey($seed->id)->lockForUpdate()->firstOrFail();
            self::request($a, $expectedDigest, (int) $actor->id);
            $now = $this->clock->now();
            $expiry = new \DateTimeImmutable($e['expiry']['value'] ?? '1970-01-01T00:00:00Z');
            if ($a->state !== 'preview' || $a->environment !== 'live' || $extension['account']->environment !== 'live'
                || $expiry <= $now || $expiry > $now->modify('+10 years')
                || $a->observed_at->getTimestamp() !== (new \DateTimeImmutable($e['read_started_at']))->getTimestamp()
                || new \DateTimeImmutable($e['read_ended_at']) > $now
                || $a->observed_at > $now || $now >= $a->valid_until || $now >= $a->observed_at->modify('+120 seconds')
                || (!array_key_exists('refusal', $e) || $e['refusal'] !== null)
                || ($e['auto_renew'] ?? null) !== false || ($e['ordinary'] ?? null) !== true
                || ($e['hold'] ?? null) !== false || ($e['redemption'] ?? null) !== false
                || ($e['actor_id'] ?? null) !== (int) $actor->id || ($e['reason'] ?? null) !== $a->reason
                || ($e['do_not_renew'] ?? null) !== $a->do_not_renew
                || ($e['account_proof_digest'] ?? null) !== $a->account_fingerprint) {
                throw new AdoptionRefused(RefusalCode::VerificationChanged);
            }
            $comparable = $capture; unset($comparable['binding']);
            if (!hash_equals($e['capture_digest'], AdoptionMapping::stamp($comparable))) { throw new AdoptionRefused(RefusalCode::VerificationChanged); }
            $d = $capture['domain_id'] === null ? $this->reserve($lock, $capture, $target, $name) : Domain::findOrFail($capture['domain_id']);
            if ($d->reservation_name === null) {
                // Sole reviewed reservation acquisition for an existing converted pending row.
                Domain::whereKey($d->id)->update(['reservation_name' => $target->name]); $d->refresh();
            }
            if ($d->reservation_name !== $target->name || $d->price_mode !== 'standard') { throw new AdoptionRefused(RefusalCode::HoldingReview); }
            $cancellation = NativeCancellation::forAdoption($lock, $a);
            $a->fill(['cancellation_id' => $cancellation, 'state' => 'committed', 'domain_id' => $d->id, 'authority_generation' => $e['resulting_generation'], 'committed_at' => $now])->save();
            $d->attachAdoption($lock, (int) $a->id);
            NativeDomainGuard::adopt($lock, $d, $a);
            AdoptionHistory::link($lock, $d, $a, $now);
            if ($e['calendar']['issue'] <= $now->format('Y-m-d') || $e['retail']['staff_alert'] !== null) {
                $d->update(['observation' => [...($d->observation ?? []), 'adoption_review' => ['inside_issue_window' => $e['calendar']['issue'] <= $now->format('Y-m-d'), 'near_due' => $e['calendar']['due'] <= $now->format('Y-m-d'), 'price' => $e['retail']['staff_alert']]]]);
            }
            return $d->fresh();
        }); // Every duplicate/deadlock/timeout aborts the complete transaction; never catch and continue.
        // Adoption is durable before enrollment; its committed state repairs a lost schedule.
        try { app(AdoptionEnrollment::class, ['clock' => $this->clock])->repair((int) $result->id); }
        catch (\Throwable $e) {
            try { logger()->warning('Adoption enrollment deferred.', ['domain_id' => $result->id, 'exception_class' => $e::class]); }
            catch (\Throwable) { /* Logging cannot change the committed adoption outcome. */ }
        }
        return $result;
    }

    private static function request(Adoption $a, string $digest, int $actor): void
    {
        if ((int) $a->actor_id !== $actor || !hash_equals($a->request_digest, $digest)
            || !hash_equals($a->request_digest, AdoptionMapping::stamp($a->evidence))) { throw new AdoptionRefused(RefusalCode::RequestChanged); }
    }
    private static function permissions(?User $staff, array $e): void
    {
        foreach ([Staff::VIEW, Staff::ADOPT_PREVIEW, Staff::ADOPT, 'admin.domains.manage', 'admin.services.update',
            ...($e['history_link_ids'] ? ['admin.invoices.view'] : [])] as $permission) {
            if (!$staff?->hasPermission($permission)) { throw new AdoptionRefused(RefusalCode::Permission); }
        }
    }
    private function reserve(HoldingIdentityLock $lock, array $capture, Target $target, array $name): Domain
    {
        $lock->owners($target->customerId);
        // A narrowly scoped insert of our own pending table row. No native order/service/invoice is created.
        $row = new Domain([...$name, 'reservation_name' => $target->name, 'service_id' => $target->serviceId,
            'user_id' => $target->customerId, 'server_id' => $target->serverId, 'registrar_key' => 'enom',
            'account_fingerprint' => $capture['binding']->fingerprint, 'status' => 'adoption_pending', 'provenance' => 'adoption_pending',
            'authority_generation' => 0, 'confirmed_revision' => 0, 'billing_managed' => false]);
        $row->updateTimestamps();
        $id = DB::table($row->getTable())->insertGetId($row->getAttributes());
        return Domain::findOrFail($id);
    }
}
