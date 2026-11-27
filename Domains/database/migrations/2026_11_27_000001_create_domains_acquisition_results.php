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
        foreach (DB::table('jobs')->select(['id', 'payload'])->lazyById() as $job) {
            $name = json_decode($job->payload, true)['displayName'] ?? null;
            if (in_array($name, [ExecuteOperation::class, RunOperation::class], true)) {
                throw new \DomainException('Drain pending Domains operation jobs before upgrading.');
            }
        }
        AcquisitionResultSchema::install();
        Verification::check();
    }

    public function down(): void
    {
        throw new \DomainException('Acquisition result storage cannot be rolled back.');
    }
};
