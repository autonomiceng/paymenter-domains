<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/fixture-checkpoint.php';
use Illuminate\Support\Facades\{Cache, Crypt, DB, Facade};

/** Case order of every multi-case command: listed, reverse, second-first or shuffle:<seed>. Order proofs change only this line. */
const NATIVE_CASE_ORDER = 'listed';
/** Receipt instrumentation that accumulates across cases; the reset never rewinds it. */
const NATIVE_CASE_KEPT_STATICS = ['FixtureSetup'];

/**
 * Run named cases against one prepared fixture, restoring everything a case can change before the next one.
 * Each case reports a digest of the rows and fake port state it leaves, so order runs can compare effects.
 * @param array<string,Closure():void> $cases
 * @param array<string,mixed> $state the fixture's models, fake ports and services, restored to their prepared values
 */
function nativeFixtureCases(array $cases, array $state = []): void
{
    $names = array_keys($cases);
    $names = match (true) {
        NATIVE_CASE_ORDER === 'listed' => $names,
        NATIVE_CASE_ORDER === 'reverse' => array_reverse($names),
        // The second case runs straight after the checkpoint, with no earlier case or restore before it.
        NATIVE_CASE_ORDER === 'second-first' => [...array_slice($names, 1, 1), ...array_slice($names, 0, 1), ...array_slice($names, 2)],
        str_starts_with(NATIVE_CASE_ORDER, 'shuffle:') => nativeCaseShuffle($names, substr(NATIVE_CASE_ORDER, 8)),
    };
    // Process CPU spent on the shared reset, reported so cohort growth can be priced.
    $cpu = static function (): float { $u = getrusage(); return $u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec'] + ($u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec']) / 1e6; };
    $start = $cpu();
    $restore = nativeCaseCheckpoint($state);
    $prepared = nativeCaseRows();
    $reset = [$cpu() - $start, 0.0, 0.0];
    foreach ($names as $name) {
        try {
            $cases[$name]();
            $start = $cpu();
            echo 'Case '.$name.' passed; effects '.nativeCaseEffects($prepared, $state)."\n";
            $reset[2] += $cpu() - $start;
        } finally { $start = $cpu(); $restore(); $reset[1] += $cpu() - $start; }
    }
    printf("Case reset CPU-s: checkpoint %.3f, restores %.3f, effects %.3f\n", ...$reset);
}

/** A fixed permutation from a seed, without consuming the process random generator. @param list<string> $names */
function nativeCaseShuffle(array $names, string $seed): array
{
    usort($names, static fn (string $a, string $b): int => strcmp(hash('sha256', $seed.$a), hash('sha256', $seed.$b)));
    return $names;
}

/** Shallow copy of every declared property of an object, including private ones of its parents. */
function nativeObjectState(object $object): array
{
    $state = [];
    for ($class = new ReflectionClass($object); $class; $class = $class->getParentClass()) {
        foreach ($class->getProperties() as $property) {
            if ($property->isStatic() || $property->getDeclaringClass()->name !== $class->name || !$property->isInitialized($object)) { continue; }
            $state[$class->name][$property->name] = $property->getValue($object);
        }
    }
    // Dynamic properties, such as a fake port's counters on stdClass, belong to no declaring class.
    $state[''] = [];
    foreach ((new ReflectionObject($object))->getProperties() as $property) {
        if (!$property->isDefault()) { $state[''][$property->name] = $property->getValue($object); }
    }
    return [$object, $state];
}

function nativeRestoreObjectState(array $saved): void
{
    [$object, $state] = $saved;
    $dynamic = $state['']; unset($state['']);
    foreach ((new ReflectionObject($object))->getProperties() as $property) {
        if (!$property->isDefault() && !array_key_exists($property->name, $dynamic)) { unset($object->{$property->name}); }
    }
    foreach ($dynamic as $name => $value) { $object->$name = $value; }
    foreach ($state as $class => $properties) {
        foreach ($properties as $name => $value) {
            $property = new ReflectionProperty($class, $name);
            if ($property->isReadOnly()) { continue; }
            $property->setValue($object, $value);
        }
    }
}

/** Static properties declared by fixture, Domains and Paymenter classes, with Eloquent boot state. */
function nativeStatics(): array
{
    $roots = [dirname(__DIR__, 2).'/', realpath((string) getenv('PAYMENTER_TEST_FIXTURE')).'/app/'];
    $statics = [];
    foreach (get_declared_classes() as $name) {
        $class = new ReflectionClass($name);
        $file = (string) $class->getFileName();
        if (in_array($name, NATIVE_CASE_KEPT_STATICS, true)) { continue; }
        if ($name !== Illuminate\Database\Eloquent\Model::class && !array_filter($roots, static fn ($root) => str_starts_with($file, $root))) { continue; }
        foreach ($class->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if ($property->getDeclaringClass()->name !== $name || !$property->isInitialized()) { continue; }
            $value = $property->getValue();
            // Registries keyed by object, such as once-per-dispatcher hook claims, change in place; keep their entries.
            $statics[$name][$property->name] = $value instanceof WeakMap ? new NativeWeakMapEntries(iterator_to_array(nativeWeakMapPairs($value), false)) : $value;
        }
    }
    return $statics;
}

/** The entries of a WeakMap held by a static property at the checkpoint. */
final class NativeWeakMapEntries
{
    public function __construct(public readonly array $pairs) {}
    public function map(): WeakMap
    {
        $map = new WeakMap;
        foreach ($this->pairs as [$key, $value]) { $map[$key] = $value; }
        return $map;
    }
}

function nativeWeakMapPairs(WeakMap $map): Generator
{
    foreach ($map as $key => $value) { yield [$key, $value]; }
}

/** Main database objects; rows are restored separately. */
function nativeSchema(): array
{
    return DB::connection()->getPdo()->query("SELECT type, name, tbl_name, sql FROM main.sqlite_master ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Capture committed rows, sequences, schema, container, events, config (with the objects it holds), auth, session,
 * request, clocks, locale, timezone, statics, globals, fake ports, queue fakes and storage files; the closure restores them all.
 */
function nativeCaseCheckpoint(array $state = []): Closure
{
    $restoreRows = nativeFixtureCheckpoint();
    $locks = nativeArrayStore()?->locks;
    $schema = nativeSchema();
    $app = app();
    $container = [];
    foreach ((new ReflectionClass(Illuminate\Container\Container::class))->getProperties() as $property) {
        if (!$property->isStatic()) { $container[$property->name] = $property->getValue($app); }
    }
    $objects = [nativeObjectState(app('events')), nativeObjectState(app('config')), nativeObjectState(app('request'))];
    foreach (['attributes', 'request', 'query', 'server', 'files', 'cookies', 'headers'] as $bag) { $objects[] = nativeObjectState(app('request')->$bag); }
    foreach (['auth', 'session'] as $manager) {
        if (!$app->resolved($manager)) { continue; }
        $objects[] = nativeObjectState(app($manager));
        foreach ((fn () => $manager === 'auth' ? $this->guards : $this->drivers)->call(app($manager)) as $driver) { $objects[] = nativeObjectState($driver); }
    }
    foreach ($container['instances'] as $instance) {
        if (is_object($instance) && str_starts_with($instance::class, 'Illuminate\\Support\\Testing\\Fakes\\')) { $objects[] = nativeObjectState($instance); }
    }
    $walk = static function (mixed $value) use (&$walk, &$objects): void {
        if (is_array($value)) { array_map($walk, $value); return; }
        if (!is_object($value) || $value instanceof Closure || $value instanceof UnitEnum) { return; }
        $objects[] = nativeObjectState($value);
        if ($value instanceof Illuminate\Support\Collection) { $value->each($walk); }
    };
    $walk($state);
    // Paymenter keeps its settings in config as an object, and issuing an invoice advances its invoice number in place.
    $walk(app('config')->all());
    $statics = nativeStatics();
    $globals = $GLOBALS;
    $clock = [date_default_timezone_get(), setlocale(LC_ALL, 0), app()->getLocale(), Carbon\Carbon::getLocale(),
        Carbon\Carbon::getTestNow(), Carbon\CarbonImmutable::getTestNow()];
    $files = nativeStorageFiles();
    $witness = static fn (): string => hash('sha256', (string) json_encode([nativeSchema(), array_keys(app()->getBindings()),
        array_map(count(...), (fn () => $this->listeners)->call(app('events'))), app('config')->all(),
        date_default_timezone_get(), setlocale(LC_ALL, 0), app()->getLocale(), array_keys($GLOBALS),
        array_map(sha1(...), nativeStorageFiles())], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    $prepared = $witness();
    return static function () use ($restoreRows, $locks, $schema, $app, $container, $objects, $statics, $globals, $clock, $files, $witness, $prepared): void {
        while (DB::transactionLevel() > 0) { DB::rollBack(); }
        $pdo = DB::connection()->getPdo();
        $kept = array_map(serialize(...), $schema);
        $order = ['trigger' => 0, 'view' => 1, 'index' => 2, 'table' => 3];
        $added = array_filter(nativeSchema(), static fn (array $object): bool => !in_array(serialize($object), $kept, true) && !str_starts_with($object['name'], 'sqlite_autoindex_'));
        usort($added, static fn (array $a, array $b): int => $order[$a['type']] <=> $order[$b['type']]);
        foreach ($added as $object) { $pdo->exec('DROP '.strtoupper($object['type']).' IF EXISTS "'.str_replace('"', '""', $object['name']).'"'); }
        same($schema, nativeSchema(), 'Case reset restores the prepared schema; a case never alters or drops a fixture table');
        $restoreRows();
        // Cache::flush() keeps lock owners, such as a unique job's lock; operation ids repeat after the row restore.
        if (($store = nativeArrayStore()) !== null) { $store->locks = $locks ?? []; }
        foreach ($container as $name => $value) { (new ReflectionProperty(Illuminate\Container\Container::class, $name))->setValue($app, $value); }
        Facade::clearResolvedInstances();
        array_map(nativeRestoreObjectState(...), $objects);
        foreach (array_diff(get_declared_classes(), NATIVE_CASE_KEPT_STATICS) as $name) {
            $class = new ReflectionClass($name);
            foreach ($class->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->name !== $name) { continue; }
                if (isset($statics[$name]) && array_key_exists($property->name, $statics[$name])) {
                    $saved = $statics[$name][$property->name];
                    $property->setValue(null, $saved instanceof NativeWeakMapEntries ? $saved->map() : $saved);
                }
                elseif (!isset($statics[$name]) && $property->hasDefaultValue() && nativeStaticsOwned($class)) { $property->setValue(null, $property->getDefaultValue()); }
            }
        }
        foreach (array_diff_key($GLOBALS, $globals) as $name => $_) { unset($GLOBALS[$name]); }
        foreach ($globals as $name => $value) { if ($name !== 'GLOBALS' && $GLOBALS[$name] !== $value) { $GLOBALS[$name] = $value; } }
        [$zone, $locale, $appLocale, $carbonLocale, $nativeNow, $immutableNow] = $clock;
        date_default_timezone_set($zone); setlocale(LC_ALL, $locale); app()->setLocale($appLocale); Carbon\Carbon::setLocale($carbonLocale);
        Carbon\Carbon::setTestNow($nativeNow); Carbon\CarbonImmutable::setTestNow($immutableNow);
        nativeRestoreStorage($files);
        // Laravel remembers each compiled string view, such as a mail subject, by the file the storage restore removes.
        Illuminate\View\Component::flushCache();
        same($prepared, $witness(), 'Case reset restores schema, bindings, listeners, config, timezone, locale, globals and files');
    };
}

/** The fixture's array cache store, which keeps locks apart from the values Cache::flush() clears. */
function nativeArrayStore(): ?Illuminate\Cache\ArrayStore
{
    $store = Cache::store()->getStore();
    return $store instanceof Illuminate\Cache\ArrayStore ? $store : null;
}

/** Classes whose statics the reset owns: fixture, Domains and Paymenter application code. */
function nativeStaticsOwned(ReflectionClass $class): bool
{
    $file = (string) $class->getFileName();
    return str_starts_with($file, dirname(__DIR__, 2).'/') || str_starts_with($file, realpath((string) getenv('PAYMENTER_TEST_FIXTURE')).'/app/');
}

/** Files under the fixture storage directory, by relative path. @return array<string,string> */
function nativeStorageFiles(): array
{
    $root = storage_path();
    $files = [];
    if (!is_dir($root)) { return $files; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $files[substr($file->getPathname(), strlen($root))] = file_get_contents($file->getPathname()); }
    }
    ksort($files);
    return $files;
}

function nativeRestoreStorage(array $files): void
{
    $root = storage_path();
    foreach (array_diff_key(nativeStorageFiles(), $files) as $path => $_) { unlink($root.$path); }
    foreach ($files as $path => $contents) {
        if (!is_file($root.$path) || file_get_contents($root.$path) !== $contents) {
            @mkdir(dirname($root.$path), 0775, true); file_put_contents($root.$path, $contents);
        }
    }
}

/** Integer columns stamped from the real clock with time(); every other integer stays in the digest. Staff review
 * keys expire 15 minutes after the real issue time of their signed ticket. */
const NATIVE_CASE_WALL_CLOCK_INTEGERS = ['domains_retention_marks' => ['recorded_at'], 'domains_staff_review_keys' => ['expires_at']];

/** Committed rows by table and rowid, decrypted, with password hashes, real-clock times and real-clock stamps masked. */
function nativeCaseRows(): array
{
    $pdo = DB::connection()->getPdo();
    $normal = static function (mixed $value): mixed {
        if (!is_string($value)) { return $value; }
        if (str_starts_with($value, '$2y$')) { return 'password-hash'; }
        return nativeWallClockMask(nativeDecrypted($value));
    };
    $rows = [];
    foreach ($pdo->query("SELECT name FROM main.sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $quoted = '"'.str_replace('"', '""', $table).'"';
        $rowid = $table === 'sqlite_sequence' ? 'name' : 'rowid';
        foreach ($pdo->query('SELECT '.$rowid.' AS fixture_rowid, * FROM main.'.$quoted.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['fixture_rowid']; unset($row['fixture_rowid']);
            foreach (NATIVE_CASE_WALL_CLOCK_INTEGERS[$table] ?? [] as $column) {
                if (is_int($row[$column] ?? null) && abs($row[$column] - time()) < 86400) { $row[$column] = 'wall-clock'; }
            }
            $rows[$table][$key] = serialize(array_map($normal, $row));
        }
    }
    return $rows;
}

/**
 * Encrypted values by their plain text, including the prefixed legacy form and values inside a decrypted payload,
 * such as a retained copy of an encrypted row: every encryption uses a random IV.
 */
function nativeDecrypted(string $value): string
{
    // Possessive, so a long payload cannot exhaust the PCRE JIT stack.
    return preg_replace_callback('~(?:encrypted:v1:)?eyJpdiI6(?:[A-Za-z0-9+=]|\\\\/|/)++~', static function (array $match): string {
        try { return 'decrypted:'.nativeDecrypted(Crypt::decryptString(str_replace(['encrypted:v1:', '\\/'], ['', '/'], $match[0]))); }
        catch (Throwable) { return $match[0]; }
    }, $value) ?? throw new RuntimeException('Case digest decryption failed: '.preg_last_error_msg());
}

/** Times written by the real clock, which differ by run; the synthetic clocks are months away from it. */
function nativeWallClockMask(string $text): string
{
    return preg_replace_callback('/\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d(?:\.\d+)?(?:[+-]\d\d:\d\d|Z)?/', static fn (array $match): string =>
        abs((int) strtotime($match[0]) - time()) < 86400 ? 'wall-clock' : $match[0], $text);
}

/** Digest of the rows a case added, changed or removed against the prepared fixture, and the fake port state it leaves. */
function nativeCaseEffects(array $prepared, array $state): string
{
    $changes = [];
    $rows = nativeCaseRows();
    foreach (array_keys($rows + $prepared) as $table) {
        $after = $rows[$table] ?? []; $before = $prepared[$table] ?? [];
        foreach (array_keys($after + $before) as $key) {
            if (($after[$key] ?? null) !== ($before[$key] ?? null)) { $changes[$table][$key] = [$before[$key] ?? null, $after[$key] ?? null]; }
        }
    }
    ksort($changes);
    // An anonymous class name holds the absolute path of its file, which differs for each export: use file and line.
    $class = static function (object $value): string {
        $class = new ReflectionClass($value);
        return $class->isAnonymous() ? 'anonymous@'.basename((string) $class->getFileName()).':'.$class->getStartLine() : $value::class;
    };
    $ports = static function (mixed $value, int $depth = 0) use (&$ports, $class): mixed {
        return match (true) {
            is_array($value) => array_map(static fn ($item) => $ports($item, $depth), $value),
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Illuminate\Database\Eloquent\Model => [$value::class, $value->getKey()],
            $value instanceof Closure, $value instanceof UnitEnum => is_object($value) ? $value::class : $value,
            is_object($value) => $depth > 2 ? $class($value) : [$class($value), $ports(get_object_vars($value), $depth + 1)],
            default => $value,
        };
    };
    // Request keys, account fingerprints and digests built from them are random per process: number them by first use.
    $tokens = [];
    $effects = preg_replace_callback('/[0-9a-f]{32,}/', static function (array $match) use (&$tokens): string {
        return 'token-'.($tokens[$match[0]] ??= count($tokens) + 1);
    }, serialize([$changes, $ports($state)]));
    $effects = nativeWallClockMask($effects);
    return substr(hash('sha256', $effects), 0, 16);
}
