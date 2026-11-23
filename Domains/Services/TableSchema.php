<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\Schema;

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
        $present = array_filter(array_keys($definitions), Schema::hasTable(...));
        if ($present && count($present) !== count($definitions) && !FreshInstallation::recovering()) {
            throw new \RuntimeException('Partial retained Domains schema.');
        }
        foreach ($definitions as $table => $definition) {
            if (!Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        }
        if (!$present || FreshInstallation::recovering()) {
            static::seed();
        }
        static::verify();
    }

    protected static function seed(): void {}

    public static function verify(): void
    {
        foreach (static::definitions() as $table => $definition) {
            SchemaVerifier::verify($table, $definition);
        }
    }
}
