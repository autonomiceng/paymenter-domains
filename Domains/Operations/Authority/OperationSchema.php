<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

/**
 * Operation steps, keyed attempts and append-only evidence. FoundationSchema owns domains_operations.
 * Registry does not list this schema yet, so no install creates these tables until the writer becomes authority.
 * A step's current attempt is a logical reference checked by the writer under the operation lock, so the
 * tables have no circular reference. Child rows reference their parent and cannot outlive it.
 * Every time column is nullable, so MariaDB gives none of them an implicit default or ON UPDATE, even with
 * explicit_defaults_for_timestamp = OFF. The writer sets each required time itself.
 */
final class OperationSchema extends TableSchema
{
    public const STEPS = 'domains_operation_steps';
    public const ATTEMPTS = 'domains_operation_attempts';
    public const EVIDENCE = 'domains_operation_evidence';

    protected static function definitions(): array
    {
        return [
            self::STEPS => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('operation_id');
                $t->string('step_type', 32);
                $t->unsignedSmallInteger('sequence');
                $t->unsignedBigInteger('revision')->default(0);
                $t->unsignedBigInteger('current_attempt_id')->nullable();
                $t->unique(['operation_id', 'step_type', 'sequence'], $t->getTable().'_step_u');
                $t->foreign('operation_id', self::name($t, 'operation_f'))->references('id')->on('domains_operations')
                    ->restrictOnDelete()->restrictOnUpdate();
            },
            self::ATTEMPTS => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('step_id');
                $t->unsignedSmallInteger('ordinal');
                $t->char('claim_key', 32)->unique();
                $t->timestamp('claimed_at')->nullable();
                $t->timestamp('claim_until')->nullable();
                $t->timestamp('released_at')->nullable();
                $t->unsignedBigInteger('operation_revision')->nullable();
                $t->char('request_digest', 64)->nullable();
                $t->timestamp('sealed_at')->nullable();
                $t->timestamp('dispatch_until')->nullable();
                $t->string('send_state', 32);
                $t->timestamp('grant_consumed_at')->nullable();
                $t->string('authorized_amount', 32)->nullable();
                $t->char('authorized_currency', 3)->nullable();
                $t->string('mode', 16)->nullable();
                $t->char('read_token', 32)->nullable()->unique();
                $t->timestamp('read_until')->nullable();
                $t->boolean('read_unresolved')->default(false);
                $t->unique(['step_id', 'ordinal'], $t->getTable().'_ordinal_u');
                $t->foreign('step_id', self::name($t, 'step_f'))->references('id')->on(self::STEPS)
                    ->restrictOnDelete()->restrictOnUpdate();
            },
            self::EVIDENCE => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('operation_id');
                $t->unsignedBigInteger('attempt_id')->nullable();
                $t->string('source', 32);
                $t->string('kind', 32);
                $t->string('outcome', 32);
                $t->timestamp('observed_at')->nullable();
                $t->timestamp('recorded_at')->nullable();
                $t->char('authority_key', 64)->unique();
                $t->longText('evidence');
                $t->index(['operation_id', 'kind'], $t->getTable().'_kind_i');
                $t->index(['attempt_id', 'kind'], $t->getTable().'_attempt_i');
                $t->foreign('operation_id', self::name($t, 'operation_f'))->references('id')->on('domains_operations')
                    ->restrictOnDelete()->restrictOnUpdate();
                $t->foreign('attempt_id', self::name($t, 'attempt_f'))->references('id')->on(self::ATTEMPTS)
                    ->restrictOnDelete()->restrictOnUpdate();
            },
        ];
    }

    /** Constraint names are unique per database on MariaDB, so they carry the connection prefix. */
    private static function name(Blueprint $t, string $suffix): string
    { return $t->getPrefix().$t->getTable().'_'.$suffix; }
}
