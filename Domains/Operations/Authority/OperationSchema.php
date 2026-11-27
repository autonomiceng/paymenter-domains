<?php
// Operation columns adapted from Enom d2c55e2; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;
use Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementProgress;

/**
 * Operations, their steps, keyed attempts and append-only evidence.
 * A step's current attempt is a logical reference checked by the writer under the operation lock, so the
 * tables have no circular reference. Child rows reference their parent and cannot outlive it.
 * Every time column is nullable, so MariaDB gives none of them an implicit default or ON UPDATE, even with
 * explicit_defaults_for_timestamp = OFF. The writer sets each required time itself. Attempt and evidence times
 * are datetime columns holding UTC instants (OperationAttempts::at), so no session time zone converts them.
 */
final class OperationSchema extends TableSchema
{
    public const STEPS = 'domains_operation_steps';
    public const ATTEMPTS = 'domains_operation_attempts';
    public const EVIDENCE = 'domains_operation_evidence';

    protected static function definitions(): array
    {
        return [
            'domains_operations' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id');
                $t->string('registrar_key', 32);
                $t->string('account_fingerprint', 64)->nullable();
                $t->unsignedBigInteger('authority_generation')->nullable();
                $t->unsignedBigInteger('domain_id')->index();
                $t->unsignedBigInteger('service_id')->index();
                $t->unsignedBigInteger('invoice_id')->nullable()->index();
                $t->unsignedBigInteger('invoice_item_id')->nullable();
                $t->string('operation_key', 191)->unique();
                $t->string('action', 32);
                $t->unsignedSmallInteger('term')->default(1);
                $t->string('funding_basis', 32)->default('paid_invoice');
                $t->string('state', 32)->index();
                $t->decimal('amount', 12, 2)->default(0);
                $t->string('currency', 3);
                $t->date('base_expires_at')->nullable();
                $t->date('expected_expires_at')->nullable();
                $t->date('confirmed_expires_at')->nullable();
                $t->longText('payload')->nullable();
                $t->longText('policy')->nullable();
                $t->longText('cost')->nullable();
                $t->string('request_fingerprint', 64)->nullable();
                $t->string('safe_code', 64)->nullable();
                $t->unsignedBigInteger('transition_revision')->default(0);
                $t->longText('attempt')->nullable();
                $t->longText('funding')->nullable();
                $t->string('provider_order_id', 128)->nullable();
                $t->unsignedBigInteger('authorized_by')->nullable();
                $t->string('funding_reason', 500)->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('lease_expires_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->boolean('management_pending')->nullable();
                $t->unsignedTinyInteger('retry_number')->default(0);
                $t->char('renewal_previous_proof', 64)->nullable();
                $t->index(['server_id', 'account_fingerprint', 'id']);
                $t->index(['domain_id', 'authority_generation', 'account_fingerprint', 'action', 'management_pending', 'id'], $t->getTable().'_management_progress_i');
                $t->unique(['invoice_item_id', 'action', 'retry_number'], $t->getTable().'_debt_retry_u');
            },
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
                $t->dateTime('claimed_at')->nullable();
                $t->dateTime('claim_until')->nullable();
                $t->dateTime('released_at')->nullable();
                $t->unsignedBigInteger('operation_revision')->nullable();
                $t->char('request_digest', 64)->nullable();
                $t->dateTime('sealed_at')->nullable();
                $t->dateTime('dispatch_until')->nullable();
                $t->string('send_state', 32);
                $t->dateTime('grant_consumed_at')->nullable();
                $t->string('authorized_amount', 32)->nullable();
                $t->char('authorized_currency', 3)->nullable();
                $t->string('mode', 16)->nullable();
                $t->char('read_token', 32)->nullable()->unique();
                $t->dateTime('read_until')->nullable();
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
                $t->dateTime('observed_at')->nullable();
                $t->dateTime('recorded_at')->nullable();
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

    public static function verify(): void
    {
        parent::verify();
        if (DB::table('domains_operations')->whereIn('action', ManagementProgress::ACTIONS)
            ->where('state', 'held')->whereNotNull('attempt')->whereNull('management_pending')->exists()) {
            throw new \RuntimeException('Retained management operation has no progress state.');
        }
    }
}
