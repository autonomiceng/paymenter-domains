<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class PslReviewSchema
{
    private static function candidates(Blueprint $t): void
    {
        $t->unsignedInteger('id')->primary(); $t->string('revision', 64); $t->string('baseline_revision', 64);
        $t->string('source_url', 2048); $t->longText('compressed_body'); $t->string('safe_code', 40);
        $t->unsignedBigInteger('fetched_at');
    }
    private static function audit(Blueprint $t): void
    {
        $t->id(); $t->string('subject_type', 40); $t->unsignedBigInteger('subject_id');
        $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('actor_id')->nullable();
        $t->string('event', 64); $t->text('reason')->nullable();
        $t->string('before_revision', 64)->nullable(); $t->string('after_revision', 64);
        $t->longText('data'); $t->timestamp('created_at');
    }
    public static function install(): void
    {
        $a = Schema::hasTable('domains_psl_candidates'); $b = Schema::hasTable('domains_watch_audit');
        if ($a !== $b) { throw new \RuntimeException('Partial retained suffix review schema.'); }
        if (!$a) { Schema::create('domains_psl_candidates', self::candidates(...)); Schema::create('domains_watch_audit', self::audit(...)); }
        self::verify();
    }
    public static function verify(): void
    {
        SchemaVerifier::verify('domains_psl_candidates', self::candidates(...));
        SchemaVerifier::verify('domains_watch_audit', self::audit(...));
    }
}
