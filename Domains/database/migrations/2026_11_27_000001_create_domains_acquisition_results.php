<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Installation\{Installer, Verification};
use Paymenter\Extensions\Servers\Domains\Jobs\{ExecuteOperation, RunOperation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\AcquisitionResultSchema;

return new class extends Migration {
    public function up(): void
    {
        // The fresh baseline already creates the final table under the owning installation intent.
        if (Installer::recovering()) { return; }
        if (!Installer::upgradingAcquisitions()) {
            throw new \DomainException('The acquisition result upgrade requires the Domains installer.');
        }
        if (DB::table('domains_accounts')->where('processing_enabled', true)->exists()
            || DB::table('domains_operations')->whereIn('action', ['register', 'transfer'])->exists()
            || DB::table('domains_runtime_operations as work')->whereNotExists(static fn ($query) => $query
                ->from('domains_operations as op')->whereColumn('op.id', 'work.operation_id'))->exists()) {
            throw new \DomainException('Pause processing and retire the demo acquisition fixtures with their runnable work before upgrading.');
        }
        $this->requireDrainedQueue();
        AcquisitionResultSchema::install();
        Verification::check();
    }

    private function requireDrainedQueue(): void
    {
        // Stop dispatch producers and wait for workers to exit before this scan and until the migration returns.
        $queue = config('queue.connections')[config('queue.default')] ?? null;
        if (($queue['driver'] ?? null) !== 'database' || !is_string($queue['table'] ?? null) || $queue['table'] === '') {
            throw new \DomainException('The acquisition result upgrade requires a drained database queue.');
        }
        $connection = DB::connection($queue['connection'] ?? null);
        $operationJobs = [ExecuteOperation::class, RunOperation::class];
        foreach ($connection->table($queue['table'])->useWritePdo()->select(['id', 'payload'])->lazyById() as $job) {
            $payload = json_decode($job->payload, true);
            if (!is_array($payload)) {
                throw new \DomainException('The configured queue contains an unreadable job. Inspect and drain it before upgrading.');
            }
            if (in_array($payload['displayName'] ?? null, $operationJobs, true)
                || in_array($payload['data']['commandName'] ?? null, $operationJobs, true)) {
                throw new \DomainException('Drain pending Domains operation jobs before upgrading.');
            }
        }
    }

    public function down(): void
    {
        throw new \DomainException('Acquisition result storage cannot be rolled back.');
    }
};
