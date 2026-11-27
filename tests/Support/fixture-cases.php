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
    $restore = nativeCaseCheckpoint($state, $cases);
    $prepared = nativeCaseRows();
    $reset = [$cpu() - $start, 0.0, 0.0];
    foreach ($names as $name) {
        try {
            NativeFixtureRandom::reseed('case:'.$name);
            $cases[$name]();
            $start = $cpu();
            echo 'Case '.$name.' passed; effects '.nativeCaseEffects($prepared, $state)."\n";
            $reset[2] += $cpu() - $start;
        } finally { $start = $cpu(); $restore(); $reset[1] += $cpu() - $start; }
    }
    printf("Case reset CPU-s: checkpoint %.3f, restores %.3f, effects %.3f\n", ...$reset);
}

/** Number the ULIDs Laravel gives new rows, such as native carts, from a fresh series instead of the real clock and
 * random bytes. Call it before the prepared fixture with one series and at the start of each case with another. */
function nativeCaseUlids(string $series): void
{
    $next = 0;
    Illuminate\Support\Str::createUlidsUsing(static function () use ($series, &$next): Symfony\Component\Uid\Ulid {
        return new Symfony\Component\Uid\Ulid(sprintf('01%s%022d', $series, ++$next));
    });
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

/** Objects reachable from a value through arrays, properties and closures, each once. @return list<object> */
function nativeStateObjects(mixed $root): array
{
    $found = []; $pending = [$root];
    while ($pending !== []) {
        $value = array_pop($pending);
        if (is_array($value)) { array_push($pending, ...array_values($value)); continue; }
        if (!is_object($value) || isset($found[spl_object_id($value)]) || nativeStateBoundary($value)) { continue; }
        if ($value instanceof Closure) {
            $found[spl_object_id($value)] = $value;
            $function = new ReflectionFunction($value);
            $pending[] = $function->getStaticVariables(); $pending[] = $function->getClosureThis();
            continue;
        }
        $found[spl_object_id($value)] = $value;
        foreach (nativeObjectState($value)[1] as $properties) { $pending[] = $properties; }
    }
    return array_values($found);
}

/** Objects with their own restore, or with no properties to copy: internal classes other than stdClass. */
function nativeStateBoundary(object $value): bool
{
    if ($value instanceof UnitEnum || $value instanceof Illuminate\Contracts\Container\Container || $value instanceof Illuminate\Database\ConnectionInterface
        || $value instanceof Illuminate\Contracts\Events\Dispatcher || $value instanceof Illuminate\Contracts\Cache\Repository
        || $value instanceof Illuminate\Contracts\Cache\Store) { return true; }
    if ($value instanceof stdClass || $value instanceof Closure) { return false; }
    for ($class = new ReflectionObject($value); $class->getParentClass(); $class = $class->getParentClass()) {}
    return $class->isInternal();
}

/** A value with every object replaced by its identity, for a witness that sees a replaced handler or service. */
function nativeIdentities(mixed $value): mixed
{
    return match (true) {
        is_array($value) => array_map(nativeIdentities(...), $value),
        $value instanceof UnitEnum => $value,
        is_object($value) => '#'.spl_object_id($value),
        default => $value,
    };
}

/** The current error and exception handlers, left in place. */
function nativeHandlers(): array
{
    $error = set_error_handler(null); restore_error_handler();
    $exception = set_exception_handler(null); restore_exception_handler();
    return [$error, $exception];
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
function nativeCaseCheckpoint(array $state = [], array $cases = []): Closure
{
    $restoreRows = nativeFixtureCheckpoint();
    $store = nativeArrayStore();
    $cache = $store === null ? null : [(fn () => $this->storage)->call($store), $store->locks];
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
    // Every object the fixture state, the cases and config reach, however deep: a port held by a service or a closure is
    // restored too. Paymenter keeps its settings in config as an object, and issuing an invoice advances its invoice number in place.
    $captured = [];
    foreach (nativeStateObjects([$state, $cases, app('config')->all()]) as $object) {
        if ($object instanceof Closure) { $captured[] = $object; } else { $objects[] = nativeObjectState($object); }
    }
    $statics = nativeStatics();
    // By value: a global a closure captures by reference, such as a request key counter, is a reference in $GLOBALS,
    // and a plain copy of the array would follow it instead of keeping the prepared value.
    $globals = [];
    foreach ($GLOBALS as $name => $value) { $globals[$name] = $value; }
    $clock = [date_default_timezone_get(), setlocale(LC_ALL, 0), app()->getLocale(), Carbon\Carbon::getLocale(),
        Carbon\Carbon::getTestNow(), Carbon\CarbonImmutable::getTestNow()];
    $files = nativeStorageFiles();
    $process = [getcwd(), getenv()];
    // Handlers, bindings and listeners by identity, so a replacement with the same count is seen; a closure's captured
    // variables by value, since the reset cannot rewrite them.
    $witness = static fn (): array => array_map(static fn (mixed $part): string => hash('sha256', (string) json_encode($part,
        JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)), ['schema' => nativeSchema(), 'bindings' => nativeIdentities(app()->getBindings()),
        'listeners' => nativeIdentities((fn () => [$this->listeners, $this->wildcards])->call(app('events'))), 'config' => app('config')->all(),
        'locale' => [date_default_timezone_get(), setlocale(LC_ALL, 0), app()->getLocale()], 'globals' => array_keys($GLOBALS),
        'process' => [getcwd(), getenv()], 'handlers' => nativeIdentities(nativeHandlers()),
        'captured' => array_map(static fn (Closure $c) => nativeIdentities((new ReflectionFunction($c))->getStaticVariables()), $captured),
        'files' => array_map(sha1(...), nativeStorageFiles())]);
    $prepared = $witness();
    return static function () use ($restoreRows, $store, $cache, $schema, $app, $container, $objects, $statics, $globals, $clock, $files, $process, $witness, $prepared): void {
        while (DB::transactionLevel() > 0) { DB::rollBack(); }
        $pdo = DB::connection()->getPdo();
        $kept = array_map(serialize(...), $schema);
        $order = ['trigger' => 0, 'view' => 1, 'index' => 2, 'table' => 3];
        $added = array_filter(nativeSchema(), static fn (array $object): bool => !in_array(serialize($object), $kept, true) && !str_starts_with($object['name'], 'sqlite_autoindex_'));
        usort($added, static fn (array $a, array $b): int => $order[$a['type']] <=> $order[$b['type']]);
        foreach ($added as $object) { $pdo->exec('DROP '.strtoupper($object['type']).' IF EXISTS "'.str_replace('"', '""', $object['name']).'"'); }
        same($schema, nativeSchema(), 'Case reset restores the prepared schema; a case never alters or drops a fixture table');
        $restoreRows();
        // The prepared cache entries and lock owners, such as a unique job's lock, come back after Cache::flush(): the first
        // case must not see entries a later case would have to rebuild. They go into the store captured at the checkpoint, so a
        // case that changed cache.default or swapped the store cannot skip or misdirect the restore.
        if ($store !== null && $cache !== null) {
            [$entries, $store->locks] = $cache; (fn () => $this->storage = $entries)->call($store);
        }
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
        // Handlers are a stack with an error mask PHP cannot read back: a case restores any handler it sets, and the witness checks it.
        [$cwd, $env] = $process; chdir($cwd);
        foreach (array_diff_key(getenv(), $env) as $name => $_) { putenv($name); }
        foreach ($env as $name => $value) { if (getenv($name) !== $value) { putenv($name.'='.$value); } }
        // Laravel remembers each compiled string view, such as a mail subject, by the file the storage restore removes.
        Illuminate\View\Component::flushCache();
        $changed = array_keys(array_diff_assoc($witness(), $prepared));
        same([], $changed, 'Case reset restores schema, bindings, listeners, config, locale, globals, environment, directory, '
            .'captured closure variables and files; a case restores the handlers it sets, and runs what it cannot rewind in its own process. Changed: '.implode(', ', $changed));
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

/** Committed rows by table and rowid, decrypted and with password hashes masked; times stay exact under the fixed clock. */
function nativeCaseRows(): array
{
    $pdo = DB::connection()->getPdo();
    $normal = static function (mixed $value): mixed {
        if (!is_string($value)) { return $value; }
        if (str_starts_with($value, '$2y$')) { return 'password-hash'; }
        return nativeDecrypted($value);
    };
    $rows = [];
    foreach ($pdo->query("SELECT name FROM main.sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $quoted = '"'.str_replace('"', '""', $table).'"';
        $rowid = $table === 'sqlite_sequence' ? 'name' : 'rowid';
        foreach ($pdo->query('SELECT '.$rowid.' AS fixture_rowid, * FROM main.'.$quoted.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['fixture_rowid']; unset($row['fixture_rowid']);
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
    // Fake port state at every depth, by class and property; an object met again is named by its first visit.
    $seen = [];
    $ports = static function (mixed $value) use (&$ports, &$seen, $class): mixed {
        if (is_array($value)) { return array_map($ports, $value); }
        if (!is_object($value)) { return $value; }
        return match (true) {
            $value instanceof UnitEnum => $value::class.'::'.$value->name,
            $value instanceof DateTimeInterface => $value->format('Y-m-d\\TH:i:s.uP'),
            $value instanceof Illuminate\Database\Eloquent\Model => [$value::class, $value->getKey()],
            $value instanceof Closure => Closure::class,
            isset($seen[spl_object_id($value)]) => 'object-'.$seen[spl_object_id($value)],
            nativeStateBoundary($value) => $class($value),
            // Property groups by class depth: an anonymous class's name holds its file path.
            default => [$class($value), ($seen[spl_object_id($value)] = count($seen) + 1) ? $ports(array_values(nativeObjectState($value)[1])) : null],
        };
    };
    // Keys and digests are exact: the native fixture seeds the random bytes Domains draws.
    return substr(hash('sha256', serialize([$changes, $ports($state)])), 0, 16);
}
