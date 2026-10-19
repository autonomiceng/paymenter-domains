<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
final class PslSchema
{
    private static function snapshots(Blueprint $t): void
    {
        $t->id(); $t->string('revision', 64)->unique(); $t->string('state', 8)->unique();
        $t->string('source_url', 2048); $t->string('etag', 512)->nullable(); $t->string('last_modified', 512)->nullable();
        foreach (['byte_size', 'icann_count', 'private_count'] as $field) { $t->unsignedInteger($field); }
        foreach (['fetched_at', 'validated_at'] as $field) { $t->unsignedBigInteger($field); }
        $t->longText('compressed_body'); // Base64(gzip): portable across native SQLite and MariaDB.
    }
    private static function status(Blueprint $t): void
    {
        $t->unsignedInteger('id')->primary();
        foreach (['last_attempt_at', 'last_network_fetch_at', 'checked_at'] as $field) { $t->unsignedBigInteger($field)->nullable(); }
        $t->string('attempt_token', 32)->nullable(); $t->string('last_result', 40)->nullable(); $t->unsignedInteger('consecutive_failures')->default(0);
    }
    public static function install(): void
    {
        $tables = ['domains_psl_snapshots', 'domains_psl_status'];
        $present = array_map(Schema::hasTable(...), $tables);
        if (in_array(true, $present, true) && in_array(false, $present, true)) { throw new \RuntimeException('Partial retained public suffix schema.'); }
        if (!$present[0]) {
            Schema::create($tables[0], self::snapshots(...)); Schema::create($tables[1], self::status(...));
            DB::table($tables[1])->insert(['id' => 1]);
        }
        self::verify();
    }
    public static function verify(): void
    {
        SchemaVerifier::verify('domains_psl_snapshots', self::snapshots(...));
        SchemaVerifier::verify('domains_psl_status', self::status(...));
        if (DB::table('domains_psl_status')->count() !== 1 || !DB::table('domains_psl_status')->where('id', 1)->exists()) {
            throw new \RuntimeException('Retained public suffix status is missing.');
        }
    }
}
