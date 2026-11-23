<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class WatchSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_watch_product_maps' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('product_id')->unique();
                $t->string('property_key', 64);
                $t->boolean('enabled')->default(false);
                $t->unsignedBigInteger('expected_ns_profile_id')->nullable();
                $t->unsignedBigInteger('revision')->default(1);
                $t->unsignedBigInteger('edited_by');
                $t->text('reason');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->index(['expected_ns_profile_id']);
            },
            'domains_watch_names' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('canonical_name', 253);
                $t->string('display_name', 253)->nullable();
                $t->string('psl_revision', 64);
                $t->string('state', 16);
                $t->unsignedBigInteger('revision')->default(1);
                $t->string('origin', 16)->default('service');
                $t->string('registration', 16)->nullable();
                $t->longText('registration_evidence')->nullable();
                $t->string('expected_dns', 16)->nullable();
                $t->unsignedBigInteger('ns_profile_id')->nullable();
                $t->unsignedBigInteger('profile_revision')->default(0);
                $t->unsignedBigInteger('managed_domain_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->json('notice_preferences');
                $t->unsignedBigInteger('last_observation_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['user_id', 'canonical_name']);
                $t->index(['ns_profile_id', 'state']);
            },
            'domains_watch_sources' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('service_id')->nullable()->unique();
                $t->unsignedBigInteger('map_id');
                $t->unsignedBigInteger('map_revision');
                $t->string('hostname', 253)->nullable();
                $t->string('registrable_name', 253)->nullable();
                $t->unsignedBigInteger('watch_id')->nullable();
                $t->unsignedBigInteger('managed_domain_id')->nullable();
                $t->string('state', 16);
                $t->string('safe_code', 64)->nullable();
                $t->string('source_digest', 64);
                $t->unsignedBigInteger('revision')->default(1);
                $t->unsignedBigInteger('last_seen_at');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->index(['user_id', 'id']);
                $t->index(['watch_id', 'state']);
                $t->index(['map_id', 'state']);
            },
        ];
    }
}
