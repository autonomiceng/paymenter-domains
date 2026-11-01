<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, WatchName, WatchSource, WatchProductMap, WatchProfile, WatchAudit};
/** Repairable postcommit enrollment. The committed adoption itself is the durable retry intent. */
final readonly class AdoptionEnrollment
{
    public function __construct(private Clock $clock) {}
    public function repair(int $id): void
    {
        OperationClaims::outside(); $seed = Domain::findOrFail($id);
        if ($seed->provenance !== 'adopted') { return; }
        $prior = $seed->observation['adoption_enrollment'] ?? [];
        if (($prior['adoption_id'] ?? null) === $seed->adoption_id && ($prior['generation'] ?? null) === $seed->authority_generation) { return; }
        $discovery = WatchSource::where('user_id', $seed->user_id)->where('registrable_name', $seed->domain)->where('state', 'watch')->orderBy('id')->limit(101)->get();
        if ($discovery->count() > 100) { throw new \DomainException('Adoption source enrollment requires staff review.'); }
        DB::transaction(function () use ($seed, $discovery): void {
            $l = HoldingLocks::take($seed, additionalServices: $discovery->pluck('service_id')->all()); $d = $l['domain'];
            HoldingAnchor::resolve($l);
            $prior = $d->observation['adoption_enrollment'] ?? [];
            if (($prior['adoption_id'] ?? null) === $d->adoption_id && ($prior['generation'] ?? null) === $d->authority_generation) { return; }
            $maps = WatchProductMap::whereKey($discovery->pluck('map_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $candidate = WatchName::where('user_id', $d->user_id)->where('canonical_name', $d->domain)->first();
            $profile = $candidate?->ns_profile_id ? WatchProfile::whereKey($candidate->ns_profile_id)->lockForUpdate()->first() : null;
            $watch = $candidate ? WatchName::whereKey($candidate->id)->lockForUpdate()->firstOrFail() : null;
            if ($watch && ((int) $watch->ns_profile_id !== (int) $profile?->id)) {
                throw new \DomainException('Adoption DNS profile changed; retry enrollment.');
            }
            $sources = WatchSource::where('user_id', $d->user_id)->where('registrable_name', $d->domain)->where('state', 'watch')->orderBy('id')->lockForUpdate()->get();
            if ($sources->pluck('id')->all() !== $discovery->pluck('id')->all()) { throw new \DomainException('Adoption sources changed; retry enrollment.'); }
            if ($sources->isNotEmpty() && (!$watch || !in_array($watch->state, ['active', 'paused'], true) || $sources->contains(fn ($s) => (int) $s->watch_id !== (int) $watch->id))) {
                throw new \DomainException('Adoption source target requires staff review.');
            }
            foreach ($sources as $source) {
                $s = $l['services']->firstWhere('id', $source->service_id); $map = $maps[$source->map_id] ?? null;
                $order = $l['orders']->firstWhere('id', $s?->order_id);
                $props = $l['relatedProperties']->where('model_id', $s?->id)->where('key', $map?->property_key);
                if (!$s || !$order || !$map || (int) $s->user_id !== (int) $d->user_id || (int) $order->user_id !== (int) $d->user_id
                    || $s->status !== 'active' || (int) $s->product_id !== (int) $map->product_id || !$map->enabled
                    || (int) $map->revision !== (int) $source->map_revision || $props->count() !== 1
                    || \Paymenter\Extensions\Servers\Domains\Dns\DnsQuery::hostname($props->sole()->value) !== $source->hostname) {
                    throw new \DomainException('Adoption source mapping changed; staff review required.');
                }
            }
            $linked = [];
            foreach ($sources as $source) {
                $attrs = $source->only(['user_id', 'service_id', 'map_id', 'map_revision', 'hostname', 'registrable_name', 'last_seen_at']);
                $source->retire('registration_adopted');
                if ($source->fresh()->state !== 'retired' || !WatchAudit::where('subject_type', 'source')->where('subject_id', $source->id)->where('event', 'source_retired')->exists()) { throw new \DomainException('Source retirement audit was not retained.'); }
                $new = WatchSource::create([...$attrs, 'state' => 'managed_link', 'managed_domain_id' => $d->id,
                    'source_digest' => AdoptionMapping::stamp([$source->id, $d->adoption_id, $d->authority_generation])]);
                if (!$new->exists) { throw new \DomainException('Managed source link was not retained.'); }
                $linked[] = ['before' => (int) $source->id, 'after' => (int) $new->id];
            }
            if ($watch && in_array($watch->state, ['active', 'paused'], true)) {
                $before = $watch->only(['revision', 'notice_preferences', 'last_observation_id', 'ns_profile_id', 'profile_revision']);
                $watch->fill(['state' => 'linked', 'registration' => 'platform', 'managed_domain_id' => $d->id,
                    'notice_preferences' => [...($watch->notice_preferences ?? []), 'expiry' => false], 'revision' => $watch->revision + 1]);
                if (!$watch->save()) { throw new \DomainException('Managed DNS identity was not retained.'); }
                if (Schema::hasTable('domains_watch_work')) {
                    // Keep old work identity/backoff for audit, but never run external DNS or expiry work for a managed name.
                    DB::table('domains_watch_work')->where('watch_id', $watch->id)->update(['claim' => null, 'lease_until' => null, 'safe_code' => 'registration_adopted']);
                }
                $audit = WatchAudit::create(['subject_type' => 'watch', 'subject_id' => $watch->id, 'user_id' => $d->user_id, 'event' => 'registration_adopted',
                    'reason' => 'registration_adopted', 'before_revision' => (string) $before['revision'], 'after_revision' => (string) $watch->revision,
                    'data' => ['adoption_id' => $d->adoption_id, 'domain_id' => $d->id, 'sources' => $linked, 'before' => $before, 'external_expiry_notices' => 'suppressed' , 'ns_history' => 'retained'], 'created_at' => $this->clock->now()]);
                if (!$audit->exists) { throw new \DomainException('Adoption enrollment audit was not retained.'); }
            }
            if (!Schema::hasTable(ManagedPublicWorkSchema::TABLE)) { throw new \DomainException('Managed public DNS enrollment schema is unavailable.'); }
            foreach (['delegation', 'points'] as $kind) {
                $identity = ['domain_id' => $d->id, 'user_id' => $d->user_id,
                    'generation' => $d->authority_generation, 'account_fingerprint' => $d->account_fingerprint, 'kind' => $kind,
                    'profile_id' => $profile?->id, 'profile_revision' => $profile?->revision,
                    'origin_watch_id' => $watch?->id, 'origin_observation_id' => $watch?->last_observation_id,
                    'target_stamp' => AdoptionMapping::stamp([$d->id, $d->user_id, $d->service_id, $d->domain, $d->account_fingerprint,
                        $d->authority_generation, $d->confirmed_revision, $profile?->id, $profile?->revision])];
                $this->ensure(ManagedPublicWorkSchema::TABLE, ['domain_id' => $d->id, 'generation' => $d->authority_generation, 'kind' => $kind],
                    $identity, ['due_at' => $this->clock->now()->getTimestamp(), 'safe_code' => 'awaiting_acceptance']);
            }
            if (!Schema::hasTable('domains_observation_queues')) { throw new \DomainException('Managed observation schema is unavailable. Retry after installation.'); }
            $this->ensure('domains_observation_queues', ['server_id' => $d->server_id],
                ['server_id' => $d->server_id, 'account_fingerprint' => $d->account_fingerprint]);
            $this->ensure('domains_observation_work', ['domain_id' => $d->id, 'generation' => $d->authority_generation, 'phase' => 'holding'],
                ['server_id' => $d->server_id, 'account_fingerprint' => $d->account_fingerprint,
                    'domain_id' => $d->id, 'generation' => $d->authority_generation, 'phase' => 'holding'], ['due_at' => $this->clock->now()->getTimestamp()]);
            if (!$d->update(['observation' => [...($d->observation ?? []), 'adoption_enrollment' => ['adoption_id' => $d->adoption_id,
                'generation' => $d->authority_generation, 'at' => $this->clock->now()->format(DATE_ATOM), 'watch_id' => $watch?->id,
                'sources' => $linked, 'public_dns' => 'awaiting_acceptance', 'registrar_observation' => 'enrolled']]])) { throw new \DomainException('Adoption enrollment marker was not retained.'); }
        });
    }
    /** A duplicate key is reusable only when its retained identity matches this enrollment. */
    private function ensure(string $table, array $key, array $identity, array $initial = []): void
    {
        DB::table($table)->insertOrIgnore([...$identity, ...$initial]);
        $row = DB::table($table)->where($key)->lockForUpdate()->first();
        foreach ($identity as $field => $expected) {
            if (!$row || ($expected === null ? $row->$field !== null : $row->$field === null || (string) $row->$field !== (string) $expected)) {
                throw new \DomainException('Required adoption work was not retained with its expected identity.');
            }
        }
    }
}
