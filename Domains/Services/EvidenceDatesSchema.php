<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

/** Explicit evidence dates cannot acquire implicit timestamp defaults or updates. */
final class EvidenceDatesSchema
{
    public const MIGRATION = '2026_10_22_000037_use_explicit_evidence_dates';
    public const SCHEMAS = [AdoptionSchema::class, PslReviewSchema::class, HistoryLinkSchema::class,
        NativeIntentSchema::class, UserDeletionSchema::class];
    private const COLUMNS = ['domains_adoptions' => ['observed_at', 'valid_until'],
        'domains_watch_audit' => ['created_at'], 'domains_history_links' => ['linked_at'],
        'domains_native_intents' => ['created_at'], 'domains_user_deletions' => ['created_at']];

    public static function verify(string $schema): void
    {
        try { $schema::verify(); }
        catch (\RuntimeException $error) {
            if (DB::table('migrations')->where('migration', self::MIGRATION)->exists()) { throw $error; }
            self::verifyLegacy($schema);
        }
    }
    private static function verifyLegacy(string $schema): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) { $schema::verify(true); return; }
        $settings = DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS defaults_mode, @@session.sql_mode AS sql_mode');
        try {
            // Historical timestamp defaults depend on the creating session, not the upgrade session.
            $mode = implode(',', array_diff(explode(',', $settings->sql_mode), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']));
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
            foreach ([1, 0] as $defaults) {
                DB::statement('SET SESSION explicit_defaults_for_timestamp = '.$defaults);
                try { $schema::verify(true); return; }
                catch (\RuntimeException $error) { $failure = $error; }
            }
            throw $failure;
        } finally {
            DB::statement('SET SESSION explicit_defaults_for_timestamp = '.(int) $settings->defaults_mode);
            DB::statement('SET SESSION sql_mode = ?', [$settings->sql_mode]);
        }
    }
    public static function upgrade(): void
    {
        // Validate the complete retained shape before changing any column.
        foreach (self::SCHEMAS as $schema) { self::verify($schema); }
        foreach (self::COLUMNS as $table => $columns) {
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                // Change both adoption dates together so the legacy zero default cannot block the other change.
                $grammar = DB::connection()->getSchemaGrammar();
                DB::statement('ALTER TABLE '.$grammar->wrapTable($table).' '.implode(', ', array_map(
                    static fn ($column) => 'MODIFY '.$grammar->wrap($column).' DATETIME NOT NULL', $columns)));
                continue;
            }
            Schema::table($table, static function (Blueprint $t) use ($columns): void {
                foreach ($columns as $column) { $t->dateTime($column)->change(); }
            });
        }
        foreach (self::SCHEMAS as $schema) { $schema::verify(); }
    }
}
