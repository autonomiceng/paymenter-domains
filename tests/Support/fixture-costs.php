<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** Sum self CPU spent in fixture setup, counting nested fixture scopes only once. */
final class FixtureSetup
{
    public static int $migrations = 0;
    public static int $replays = 0;
    public static int $fallbacks = 0;
    public static int $restores = 0;
    private static int $depth = 0;
    private static int $start = 0;
    private static int $micros = 0;

    public function __construct()
    {
        if (self::$depth++ === 0) {
            self::$start = self::cpu();
        }
    }

    public function __destruct()
    {
        if (--self::$depth === 0) {
            self::$micros += self::cpu() - self::$start;
        }
    }

    private static function cpu(): int
    {
        $usage = getrusage();
        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000000
            + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
    }

    public static function report(): void
    {
        if (($GLOBALS['nativeSuccessful'] ?? true) !== true
            || in_array(error_get_last()['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        fwrite(STDERR, 'DOMAINS_FIXTURE_COSTS '.json_encode([
            'core_migrations' => self::$migrations,
            'core_snapshot_replays' => self::$replays,
            'commerce_fallbacks' => self::$fallbacks,
            'commerce_restores' => self::$restores,
            'setup_cpu_seconds' => self::$micros / 1000000,
        ], JSON_THROW_ON_ERROR)."\n");
    }
}

register_shutdown_function(FixtureSetup::report(...));
