<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

final class PslSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_psl_snapshots' => static function (Blueprint $t): void {
                $t->id();
                $t->string('revision', 64)->unique();
                $t->string('state', 8)->unique();
                $t->string('source_url', 2048);
                $t->string('etag', 512)->nullable();
                $t->string('last_modified', 512)->nullable();
                $t->unsignedInteger('byte_size');
                $t->unsignedInteger('icann_count');
                $t->unsignedInteger('private_count');
                $t->unsignedBigInteger('fetched_at');
                $t->unsignedBigInteger('validated_at');
                $t->longText('compressed_body');
            },
            'domains_psl_status' => static function (Blueprint $t): void {
                $t->unsignedInteger('id')->primary();
                $t->unsignedBigInteger('last_attempt_at')->nullable();
                $t->unsignedBigInteger('last_network_fetch_at')->nullable();
                $t->unsignedBigInteger('checked_at')->nullable();
                $t->string('attempt_token', 32)->nullable();
                $t->string('last_result', 40)->nullable();
                $t->unsignedInteger('consecutive_failures')->default(0);
            },
        ];
    }

    protected static function seed(): void
    {
        DB::table('domains_psl_status')->insertOrIgnore(['id' => 1]);
    }

    public static function verify(): void
    {
        parent::verify();
        if (DB::table('domains_psl_status')->count() !== 1 || !DB::table('domains_psl_status')->where('id', 1)->exists()) {
            throw new \RuntimeException('Missing domains_psl_status singleton.');
        }
    }
}
