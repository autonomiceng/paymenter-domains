<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class WatchSchema
{
    private static function maps(Blueprint $t): void
    {
        $t->id(); $t->unsignedBigInteger('product_id')->unique(); $t->string('property_key', 64);
        $t->boolean('enabled')->default(false); $t->unsignedBigInteger('expected_ns_profile_id')->nullable();
        $t->unsignedBigInteger('revision')->default(1); $t->unsignedBigInteger('edited_by'); $t->text('reason'); $t->timestamps();
    }
    private static function names(Blueprint $t): void
    {
        $t->id(); $t->unsignedBigInteger('user_id'); $t->string('canonical_name', 253); $t->string('display_name', 253)->nullable();
        $t->string('psl_revision', 64); $t->string('state', 16); $t->unsignedBigInteger('revision')->default(1);
        $t->string('origin', 16)->default('service'); $t->string('registration', 16)->nullable(); $t->longText('registration_evidence')->nullable();
        $t->string('expected_dns', 16)->nullable(); $t->unsignedBigInteger('ns_profile_id')->nullable();
        $t->unsignedBigInteger('profile_revision')->default(0); $t->unsignedBigInteger('managed_domain_id')->nullable();
        $t->unsignedBigInteger('created_by')->nullable(); $t->json('notice_preferences'); $t->unsignedBigInteger('last_observation_id')->nullable();
        $t->timestamps(); $t->unique(['user_id', 'canonical_name']);
    }
    private static function sources(Blueprint $t): void
    {
        $t->id(); $t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('service_id')->nullable()->unique(); // Retired rows release this active identity; audit keeps its prior value.
        $t->unsignedBigInteger('map_id'); $t->unsignedBigInteger('map_revision');
        $t->string('hostname', 253)->nullable(); $t->string('registrable_name', 253)->nullable();
        $t->unsignedBigInteger('watch_id')->nullable(); $t->unsignedBigInteger('managed_domain_id')->nullable();
        $t->string('state', 16); $t->string('safe_code', 64)->nullable(); $t->string('source_digest', 64);
        $t->unsignedBigInteger('revision')->default(1); $t->unsignedBigInteger('last_seen_at'); $t->timestamps();
        $t->index(['watch_id', 'state']); $t->index(['map_id', 'state']);
    }
    public static function tables(): array { return ['domains_watch_product_maps', 'domains_watch_names', 'domains_watch_sources']; }
    public static function install(): void
    {
        PslReviewSchema::verify(); // The shared append-only audit was installed with suffix review.
        $present = array_map(Schema::hasTable(...), self::tables());
        if (in_array(true, $present, true) && in_array(false, $present, true)) { throw new \RuntimeException('Partial retained watch schema.'); }
        if (!$present[0]) {
            Schema::create(self::tables()[0], self::maps(...)); Schema::create(self::tables()[1], self::names(...)); Schema::create(self::tables()[2], self::sources(...));
        }
        self::verify();
    }
    public static function verify(bool $legacyDates = false): void
    {
        PslReviewSchema::verify($legacyDates);
        SchemaVerifier::verify(self::tables()[0], self::maps(...)); SchemaVerifier::verify(self::tables()[1], self::names(...));
        SchemaVerifier::verify(self::tables()[2], self::sources(...));
    }
}
