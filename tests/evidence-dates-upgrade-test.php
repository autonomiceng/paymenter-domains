<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
if (!($evidenceDatesBootstrapped ?? false)) { require __DIR__.'/native-app.php'; }
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema,EvidenceDatesSchema,HistoryLinkSchema,Installation,NativeIntentSchema,PslReviewSchema,UserDeletionSchema};

$dateDefinitions = [
    ['domains_adoptions', AdoptionSchema::class, 'definition', ['observed_at', 'valid_until']],
    ['domains_watch_audit', PslReviewSchema::class, 'audit', ['created_at']],
    ['domains_history_links', HistoryLinkSchema::class, 'definition', ['linked_at']],
    ['domains_native_intents', NativeIntentSchema::class, 'definition', ['created_at']],
    ['domains_user_deletions', UserDeletionSchema::class, 'definition', ['created_at']],
];
$mysql = new class(static fn () => throw new LogicException('Offline grammar must not connect'), '', '',
    ['version' => '8.0.32', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']) extends MySqlConnection {
    public function getServerVersion(): string { return '8.0.32'; }
    public function isMaria() { return false; }
};
$mysql->useDefaultSchemaGrammar();
foreach ($dateDefinitions as [$table, $schema, $method, $dates]) {
    $blueprint = new Blueprint($mysql, $table); $blueprint->create();
    (new ReflectionMethod($schema, $method))->invoke(null, $blueprint);
    $sql = implode(';', $blueprint->toSql());
    foreach ($dates as $date) {
        expect(str_contains($sql, '`'.$date.'` datetime not null'), $table.'.'.$date.' requires an explicit datetime');
    }
}

$installation = new Installation; $installation->install();
$migration = EvidenceDatesSchema::MIGRATION;
$record = (array) DB::table('migrations')->where('migration', $migration)->sole();
$when = '2026-10-18 01:02:03';
$rows = [
    'domains_adoptions' => ['request_key' => 'date-upgrade', 'request_digest' => str_repeat('a', 64),
        'service_id' => 700, 'user_id' => 701, 'server_id' => 702, 'registrar_key' => 'enom', 'environment' => 'test',
        'account_fingerprint' => str_repeat('b', 64), 'state' => 'preview', 'origin' => 'synthetic',
        'evidence' => 'retained ciphertext', 'actor_id' => 703, 'reason' => 'Before', 'do_not_renew' => false,
        'observed_at' => $when, 'valid_until' => '2026-10-18 02:02:03'],
    'domains_watch_audit' => ['subject_type' => 'synthetic', 'subject_id' => 700, 'event' => 'review',
        'after_revision' => str_repeat('c', 64), 'data' => 'retained ciphertext', 'created_at' => $when],
    'domains_history_links' => ['domain_id' => 700, 'service_id' => 701, 'user_id' => 702, 'invoice_id' => 703,
        'source' => 'synthetic', 'linked_by' => 704, 'reason' => 'Before', 'linked_at' => $when,
        'evidence' => 'retained ciphertext', 'link_key' => str_repeat('d', 64)],
    'domains_native_intents' => ['service_id' => 700, 'kind' => 'cancel', 'created_at' => $when],
    'domains_user_deletions' => ['user_id' => 700, 'created_at' => $when],
];
foreach ($rows as $table => $row) { DB::table($table)->insert($row); }
$snapshot = static function () use ($rows): array {
    $out = [];
    foreach ($rows as $table => $_) { $out[$table] = [DB::table($table)->get()->toJson(), Schema::getIndexes($table)]; }
    return $out;
};
$before = $snapshot();
$assertDates = static function () use ($dateDefinitions): void {
    foreach ($dateDefinitions as [$table, $_, $method, $dates]) {
        $columns = array_column(Schema::getColumns($table), null, 'name');
        foreach ($dates as $date) {
            same('datetime', $columns[$date]['type_name'], $table.'.'.$date.' stores an explicit datetime');
            same(null, $columns[$date]['default'], $table.'.'.$date.' has no implicit default');
            if (DB::connection()->getDriverName() === 'mysql') {
                $physical = DB::connection()->getTablePrefix().$table;
                $fields = array_column(DB::select('SHOW FULL COLUMNS FROM `'.$physical.'`'), null, 'Field');
                expect(!str_contains(strtolower($fields[$date]->Extra), 'on update'), $table.'.'.$date.' has no automatic update');
            }
        }
    }
};
$assertDates();
// Reproduce the shipped timestamp definitions, including MariaDB's implicit attributes.
DB::table('migrations')->where('migration', $migration)->delete();
if (DB::connection()->getDriverName() === 'mysql') {
    $sqlModeBefore = DB::selectOne('SELECT @@session.sql_mode AS value')->value;
    $legacyMode = implode(',', array_diff(explode(',', $sqlModeBefore), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']));
    DB::statement('SET SESSION sql_mode = ?', [$legacyMode]);
}
$recreateLegacy = static function () use ($dateDefinitions): void {
    foreach ($dateDefinitions as [$table, $schema, $method]) {
        $saved = array_map(static fn ($row) => (array) $row, DB::table($table)->get()->all());
        Schema::drop($table);
        Schema::create($table, static function (Blueprint $t) use ($schema, $method): void {
            (new ReflectionMethod($schema, $method))->invoke(null, $t, true);
        });
        if ($saved !== []) { DB::table($table)->insert($saved); }
    }
};
$recreateLegacy();
if (DB::connection()->getDriverName() === 'mysql') {
    $physical = DB::connection()->getTablePrefix().'domains_adoptions';
    $fields = array_column(DB::select('SHOW FULL COLUMNS FROM `'.$physical.'`'), null, 'Field');
    expect(str_contains(strtolower($fields['observed_at']->Extra), 'on update'), 'Legacy OFF fixture reproduces automatic observed_at updates');
    expect(str_contains(strtolower($fields['observed_at']->Default), 'current_timestamp'), 'Legacy OFF fixture reproduces implicit observed_at defaults');
}
if (DB::connection()->getDriverName() === 'mysql') {
    DB::statement('SET SESSION sql_mode = ?', [$sqlModeBefore]);
    DB::statement('SET SESSION explicit_defaults_for_timestamp = ON');
}
$history = DB::table('migrations')->orderBy('id')->get()->toJson();
$installation->install(); $assertDates();
if (DB::connection()->getDriverName() === 'mysql') {
    same(1, (int) DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS value')->value, 'OFF to ON upgrade restores session setting');
    same($sqlModeBefore, DB::selectOne('SELECT @@session.sql_mode AS value')->value, 'Legacy verification restores SQL mode');
}
same($before, $snapshot(), 'Additive upgrade preserves every evidence value and index');
$record = (array) DB::table('migrations')->where('migration', $migration)->sole();
same($history, DB::table('migrations')->where('migration', '!=', $migration)->orderBy('id')->get()->toJson(), 'Prior migration records retained');
$installation->install(); $installation->verify();
same($record, (array) DB::table('migrations')->where('migration', $migration)->sole(), 'Repeated upgrade preserves migration record');
same($before, $snapshot(), 'Repeated upgrade preserves retained evidence');
(require dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php')->down();
same($before, $snapshot(), 'Rollback retains evidence dates');
if (DB::connection()->getDriverName() === 'mysql') {
    // Also retain tables created under ON when the upgrade session uses OFF.
    DB::table('migrations')->where('migration', $migration)->delete();
    $recreateLegacy();
    DB::statement('SET SESSION explicit_defaults_for_timestamp = OFF');
    $installation->install(); $assertDates();
    same($before, $snapshot(), 'ON to OFF upgrade retains all evidence');
    same(0, (int) DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS value')->value, 'ON to OFF upgrade restores session setting');
    $record = (array) DB::table('migrations')->where('migration', $migration)->sole();
}
if (DB::connection()->getDriverName() === 'mysql') { DB::statement('SET timestamp = 1800000000'); }
foreach (['domains_adoptions' => ['reason' => 'After'], 'domains_watch_audit' => ['event' => 'after'],
    'domains_history_links' => ['reason' => 'After'], 'domains_native_intents' => ['kind' => 'after'],
    'domains_user_deletions' => ['user_id' => 705]] as $table => $changes) {
    DB::table($table)->update($changes);
}
foreach ($dateDefinitions as [$table, $_, $method, $dates]) {
    $row = (array) DB::table($table)->sole();
    foreach ($dates as $date) { same($rows[$table][$date], $row[$date], 'Unrelated save preserves '.$table.'.'.$date); }
}
try { DB::table('domains_native_intents')->insert(['service_id' => 706, 'kind' => 'cancel']); expect(false, 'Missing evidence date accepted'); }
catch (Illuminate\Database\QueryException $error) { expect(str_contains($error->getMessage(), 'created_at'), 'Missing evidence date has no implicit default'); }

// Pin the compatibility boundary independently of SQLite's shared date type grammar.
$legacyOnly = new class {
    public static function verify(bool $legacyDates = false): void {
        if (!$legacyDates) { throw new RuntimeException('Legacy dates'); }
    }
};
try { EvidenceDatesSchema::verify($legacyOnly::class); expect(false, 'Recorded date upgrade accepted legacy schema'); }
catch (RuntimeException $error) { same('Legacy dates', $error->getMessage(), 'Recorded upgrade refuses old dates'); }
DB::table('migrations')->where('migration', $migration)->delete();
EvidenceDatesSchema::verify($legacyOnly::class);
DB::table('migrations')->insert($record);
// Neither current nor legacy compatibility may conceal unrelated schema drift.
foreach ([true, false] as $recorded) {
    if (!$recorded) { DB::table('migrations')->where('migration', $migration)->delete(); }
    Schema::table('domains_adoptions', static fn (Blueprint $t) => $t->string('unexpected')->nullable());
    try { $installation->install(); expect(false, 'Date upgrade repaired unrelated drift'); }
    catch (RuntimeException $error) { expect(str_contains($error->getMessage(), 'schema mismatch'), 'Unrelated retained drift refused');
        if (DB::connection()->getDriverName() === 'mysql') {
            same(0, (int) DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS value')->value, 'Failed verification restores session setting');
            same($sqlModeBefore, DB::selectOne('SELECT @@session.sql_mode AS value')->value, 'Failed verification restores SQL mode');
        }
    }
    Schema::table('domains_adoptions', static fn (Blueprint $t) => $t->dropColumn('unexpected'));
    if (!$recorded) { DB::table('migrations')->insert($record); }
}
if (!($evidenceDatesBootstrapped ?? false)) { echo "Explicit evidence dates, retained upgrade, idempotency, rollback and schema refusals passed.\n"; }
$nativeSuccessful = true;
