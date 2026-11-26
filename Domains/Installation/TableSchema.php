<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Installation;

use Illuminate\Support\Facades\Schema;

/** A feature's table definitions; Registry orders them and Verification checks them. */
abstract class TableSchema
{
    abstract protected static function definitions(): array;

    public static function tables(): array
    {
        return array_keys(static::definitions());
    }

    public static function install(): void
    {
        $definitions = static::definitions();
        $owned = Installer::recovering();
        $present = self::requireWhole(array_keys($definitions), $owned);
        foreach ($definitions as $table => $definition) {
            if (!Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        }
        if (!$present || $owned) {
            static::seed();
        }
        // The owning fresh install's baseline migration verifies every registered table once, after all are created.
        if (!$owned) {
            static::verify();
        }
    }

    /** The present tables. Outside owned recovery, some but not all of them present is a partial retained schema. */
    protected static function requireWhole(array $tables, bool $owned): array
    {
        $present = array_filter($tables, Schema::hasTable(...));
        if ($present && count($present) !== count($tables) && !$owned) {
            throw new \RuntimeException('Partial retained Domains schema.');
        }
        return $present;
    }

    protected static function seed(): void {}

    public static function verify(): void
    {
        foreach (static::definitions() as $table => $definition) {
            SchemaVerifier::verify($table, $definition);
        }
    }
}
