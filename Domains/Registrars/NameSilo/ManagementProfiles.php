<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, ManagementRequest};
use Paymenter\Extensions\Servers\Domains\Models\{ContactProfile, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{ContactProfiles, ManagementEvidence};
/** Durable creation and association are separate management operations, each with one grant. */
final class ManagementProfiles
{
    public static function select(AccountBinding $binding, string $name, #[\SensitiveParameter] array $input, string $key, int $domainId): string
    {
        $d = Domain::whereKey($domainId)->where('account_fingerprint', $binding->fingerprint)->where('domain', $name)->sole();
        $ops = Operation::where('domain_id', $d->id)->orderByDesc('id')->get();
        $existing = $ops->firstWhere('operation_key', $key);
        if ($existing && is_string($frozen = $existing->policy['management']['input']['profile_operation'] ?? null)) { return $frozen; }
        foreach ($ops as $op) {
            if ($op->action !== 'contact_profile' || ($op->policy['management']['input'] ?? null) !== $input) { continue; }
            if ($ops->contains(fn ($peer) => $peer->operation_key !== $key && ($peer->policy['management']['input']['profile_operation'] ?? null) === $op->operation_key)) { continue; }
            if (ManagementEvidence::resolved($op, $d) && $op->state === 'succeeded') { return $op->operation_key; }
        }
        throw new \DomainException('Fresh contact profile staging required.');
    }
    public static function resolve(ManagementRequest $r, \DateTimeImmutable $now): ContactProfile
    {
        $p = $r->payload(); $key = $p['profile_operation'] ?? null;
        if (!is_string($key)) { throw new \DomainException('Profile staging proof required.'); }
        $op = Operation::where('operation_key', $key)->sole(); $d = $op->domain;
        $input = $p; unset($input['profile_operation']);
        $reads = $op->attempt['management_reads'] ?? []; $read = $reads ? end($reads) : [];
        $at = $read['at'] ?? null;
        if ($op->action !== 'contact_profile' || $op->state !== 'succeeded' || !ManagementEvidence::resolved($op, $d)
            || $d->id !== $r->domainId || $d->domain !== $r->name || $op->account_fingerprint !== $r->binding->fingerprint
            || ($op->policy['management']['input'] ?? null) !== $input || !is_string($at)
            || new \DateTimeImmutable($at) > $now) {
            throw new \DomainException('Fresh owner-bound profile proof required.');
        }
        // A stage is reserved by its first association request, including unsent/retired requests.
        $users = Operation::where('domain_id', $d->id)->where('action', 'contacts')->orderBy('id')->get()
            ->filter(fn ($peer) => ($peer->policy['management']['input']['profile_operation'] ?? null) === $key);
        if ($users->count() !== 1 || $users->first()->operation_key !== $r->key) { throw new \DomainException('Profile staging already consumed.'); }
        $profile = ContactProfile::whereKey($read['facts']['local_profile_id'] ?? 0)->where('user_id', $d->user_id)
            ->where('server_id', $r->binding->serverId)->where('account_fingerprint', $r->binding->fingerprint)->firstOrFail();
        if (($profile->details['provider_id'] ?? null) !== ($op->attempt['management_ack']['profile_id'] ?? null)
            || ($profile->details['contact'] ?? null) !== $p['contact']
            || ($profile->details['registration_proof']['operation'] ?? null) !== $key) { throw new \DomainException('Profile evidence changed.'); }
        return $profile;
    }
    public static function retain(ManagementRequest $r, #[\SensitiveParameter] array $contact): int
    {
        $op = Operation::where('operation_key', $r->key)->sole(); $d = $op->domain;
        if ($op->action !== 'contact_profile' || $r->action !== $op->action || $d->id !== $r->domainId || $d->domain !== $r->name
            || ($op->policy['management']['input'] ?? null) !== $r->payload() || ($r->payload()['contact'] ?? null) !== $contact
            || $op->account_fingerprint !== $r->binding->fingerprint
            || ($op->attempt['seal'] ?? null) !== 'sealed' || ($op->attempt['management_ack']['profile_id'] ?? null) !== $r->profileId
            || $d->authority_generation !== $op->authority_generation || $d->account_fingerprint !== $op->account_fingerprint) {
            throw new \DomainException('Profile creation proof unavailable.');
        }
        return (int) ContactProfiles::recordCreated($r->binding, (int) $d->user_id, $r->profileId, $contact, false,
            ['operation' => $r->key, 'observed_at' => $op->attempt['dispatch_attempted_at']])->id;
    }
}
