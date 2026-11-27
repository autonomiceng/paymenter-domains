<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;

use Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementAttempt;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AttemptFacts};

trait HasManagementAttempt
{
    private ManagementAttempt|\Throwable|null $managementAttempt = null;
    private ?AttemptFacts $managementFacts = null;
    private bool $managementLoaded = false;

    public function managementAttempt(): ?ManagementAttempt
    {
        if (!$this->managementLoaded) { self::loadManagementAttempts([$this]); }
        if ($this->managementAttempt instanceof \Throwable) { throw $this->managementAttempt; }
        return $this->managementAttempt;
    }

    private function currentManagementFacts(): ?AttemptFacts
    {
        if (!$this->managementLoaded) { self::loadManagementAttempts([$this]); }
        return $this->managementFacts;
    }

    public static function loadManagementAttempts(iterable $operations, bool $lock = false): void
    {
        $pending = [];
        foreach ($operations as $op) {
            if (!$op->managementLoaded && $op->getRawOriginal('id') !== null
                && ManagementAttempt::applies((string) $op->getRawOriginal('action'))) {
                $pending[(int) $op->id][] = $op;
            }
        }
        $attempts = ManagementAttempt::many(array_keys($pending), $lock);
        foreach ($pending as $id => $models) {
            foreach ($models as $op) {
                $op->managementAttempt = $attempts[$id]['projection'] ?? null;
                $op->managementFacts = $attempts[$id]['facts'] ?? null;
                $op->managementLoaded = true;
            }
        }
    }

    public function reloadManagementAttempt(): void
    {
        $this->managementLoaded = false;
        $this->managementAttempt = null;
        $this->managementFacts = null;
    }

    private function managementProjection(string $field): array
    {
        if ($field !== 'attempt' && $field !== 'funding') { return []; }
        return $this->managementAttempt()?->$field ?? [];
    }

    private function keepManagementEvidence(string $field, ?array $value): ?array
    {
        if ($field === 'attempt' && $this->managementAttempt()) {
            if ($value !== $this->managementAttempt->attempt) { throw new \DomainException('Management evidence belongs to its operation attempt.'); }
            return null;
        }
        $proof = $this->managementProjection($field);
        return $proof ? AcquisitionAttempt::retain($proof, $value) : $value;
    }

    private function updateManagement(\Illuminate\Database\Eloquent\Builder $query): bool
    {
        $identity = ['started_at', 'lease_expires_at', 'request_fingerprint'];
        if (!$this->isDirty(['attempt', 'payload', 'funding', ...$identity])) { return parent::performUpdate($query); }
        $connection = $this->getConnection();
        $write = function () use ($query, $identity): bool {
            $this->newModelQuery()->whereKey($this->getKeyForSaveQuery())->lockForUpdate()->firstOrFail(['id']);
            $this->reloadManagementAttempt();
            self::loadManagementAttempts([$this], lock: true);
            if (($attempt = $this->managementAttempt()) && ($this->isDirty(['attempt', ...$identity])
                    || ($this->isDirty('payload') && $attempt->facts?->sealed())
                    || ($this->isDirty('funding') && array_key_exists('seal', $this->receiptFunding())))) {
                throw new \DomainException('Management evidence belongs to its operation attempt.');
            }
            return parent::performUpdate($query);
        };
        return $connection->transactionLevel() ? $write() : $connection->transaction($write);
    }
}
