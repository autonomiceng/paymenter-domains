<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class AcquisitionResultSchema extends TableSchema
{
    public const TABLE = 'domains_acquisition_results';

    public static function insert(int $operationId): void
    {
        DB::table(self::TABLE)->insert(['operation_id' => $operationId]);
    }

    protected static function definitions(): array
    {
        return [
            self::TABLE => static function (Blueprint $table): void {
                $table->unsignedBigInteger('operation_id')->primary();
                $table->unsignedBigInteger('revision')->default(0);
                $table->dateTime('first_claim_at')->nullable();
                $table->unsignedBigInteger('window_revision')->nullable();
                $table->dateTime('confirmed_shortfall_at')->nullable();
                $table->string('retry_lane', 24)->nullable();
                $table->unsignedBigInteger('retry_due_at')->nullable();
                $table->string('window_origin', 24)->nullable();
                $table->string('window_reason', 64)->nullable();
                $table->unsignedBigInteger('window_authority_id')->nullable();
                $table->dateTime('window_started_at')->nullable();
                $table->dateTime('window_ends_at')->nullable();
                $table->dateTime('transfer_input_until')->nullable();
                $table->string('window_expiry_reason', 64)->nullable();
                $table->foreign('operation_id', $table->getPrefix().$table->getTable().'_operation_f')
                    ->references('id')->on('domains_operations')->restrictOnDelete()->restrictOnUpdate();
            },
        ];
    }

    public static function verify(): void
    {
        parent::verify();
        if (DB::table('domains_operations as op')->whereIn('op.action', ['register', 'transfer'])
            ->whereNotExists(static fn ($query) => $query->from(self::TABLE.' as result')
                ->whereColumn('result.operation_id', 'op.id'))->exists()) {
            throw new \RuntimeException('An acquisition is missing its result.');
        }
    }
}
