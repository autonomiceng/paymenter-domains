<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class ObservationSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_read_budgets' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id')->unique();
                $t->string('account_fingerprint', 64);
                $t->unsignedBigInteger('window_at');
                $t->unsignedInteger('used')->default(0);
                $t->unsignedBigInteger('next_api_at')->default(0);
                $t->text('leases');
            },
            'domains_observation_queues' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id')->unique();
                $t->string('account_fingerprint', 64);
                $t->unsignedBigInteger('catalogue_cursor')->default(0);
                $t->unsignedBigInteger('catalogue_due')->default(0);
                $t->string('catalogue_claim', 32)->nullable();
                $t->unsignedBigInteger('catalogue_lease')->default(0);
                $t->string('catalogue_hold', 32)->nullable();
                $t->unsignedInteger('catalogue_resumes')->default(0);
                $t->text('catalogue_audit')->nullable();
                $t->unsignedBigInteger('cursor')->default(0);
                $t->unsignedBigInteger('turn')->default(0);
                $t->unsignedBigInteger('refund_cursor')->default(0);
                $t->unsignedBigInteger('refund_turn')->default(0);
                $t->unsignedBigInteger('timeout_cursor')->default(0);
                $t->unsignedBigInteger('timeout_turn')->default(0);
                $t->unsignedBigInteger('offer_feed_run')->default(0);
                $t->unsignedBigInteger('offer_feed_tried_at')->nullable();
                $t->unsignedBigInteger('offer_feed_succeeded_at')->nullable();
                $t->unsignedBigInteger('offer_feed_failed_at')->nullable();
                $t->string('offer_feed_failure', 64)->nullable();
                $t->boolean('offer_queue_created')->default(false);
                $t->json('offer_work')->nullable();
                $t->string('offer_claim', 64)->nullable();
                $t->unsignedBigInteger('offer_lease')->default(0);
                $t->unsignedBigInteger('offer_turn')->default(0);
                $t->unsignedBigInteger('complimentary_cursor')->default(0);
            },
            'domains_observation_work' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id');
                $t->string('account_fingerprint', 64);
                $t->unsignedBigInteger('domain_id');
                $t->unsignedBigInteger('generation');
                $t->unsignedBigInteger('due_at')->index();
                $t->unsignedInteger('tries')->default(0);
                $t->unsignedInteger('resumes')->default(0);
                $t->string('claim', 32)->nullable();
                $t->unsignedBigInteger('lease_until')->default(0);
                $t->string('safe_code', 32)->nullable();
                $t->string('phase', 64)->default('holding');
                $t->index(['server_id', 'account_fingerprint', 'due_at'], $t->getTable().'_account_due_index');
                $t->unique(['domain_id', 'generation', 'phase']);
            },
        ];
    }
}
