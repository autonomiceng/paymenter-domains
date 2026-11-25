<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Holding\Adoption;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{FreshTransaction, HoldingAnchor, HoldingLocks, OperationClaims};
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
        FreshTransaction::run(function () use ($seed): void {
            $l = HoldingLocks::take($seed); $d = $l['domain'];
            HoldingAnchor::resolve($l);
            $prior = $d->observation['adoption_enrollment'] ?? [];
            if (($prior['adoption_id'] ?? null) === $d->adoption_id && ($prior['generation'] ?? null) === $d->authority_generation) { return; }
            $this->ensure('domains_observation_queues', ['server_id' => $d->server_id],
                ['server_id' => $d->server_id, 'account_fingerprint' => $d->account_fingerprint]);
            $this->ensure('domains_observation_work', ['domain_id' => $d->id, 'generation' => $d->authority_generation, 'phase' => 'holding'],
                ['server_id' => $d->server_id, 'account_fingerprint' => $d->account_fingerprint,
                    'domain_id' => $d->id, 'generation' => $d->authority_generation, 'phase' => 'holding'], ['due_at' => $this->clock->now()->getTimestamp()]);
            if (!$d->update(['observation' => [...($d->observation ?? []), 'adoption_enrollment' => ['adoption_id' => $d->adoption_id,
                'generation' => $d->authority_generation, 'at' => $this->clock->now()->format(DATE_ATOM),
                'registrar_observation' => 'enrolled']]])) { throw new \DomainException('Adoption enrollment marker was not retained.'); }
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
