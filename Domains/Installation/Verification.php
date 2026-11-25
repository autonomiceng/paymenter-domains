<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Installation;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migrator;

/** Full table verification and its derived record, which lets order paths skip a repeat check of unchanged schema. */
final class Verification
{
    public const KEY = 'domains_schema_verified';

    public static function run(): void
    {
        // Invalidate first: a failed explicit check must not leave an older success usable.
        self::record()->delete();
        $state = self::state();
        if (Registry::tables() !== Registry::expectedTables()) {
            throw new \RuntimeException('Retained Domains table inventory differs from the installed schema.');
        }
        SchemaVerifier::batch(static function (): void { foreach (Registry::SCHEMAS as $schema) { $schema::verify(); } });
        self::record()->create(['key'=>self::KEY,'settingable_type'=>null,'settingable_id'=>null,
            'type'=>'string','encrypted'=>false,'value'=>$state]);
    }

    /** True only while the single record matches this package, migration history and physical schema. */
    public static function current(): bool
    {
        $records = self::record()->get();
        if ($records->count() !== 1) { return false; }
        try { return $records->sole()->value === self::state(); }
        catch (\RuntimeException) { return false; } // A failed metadata read or missing migration takes the full refusal path.
    }

    public static function forOrders(): void
    {
        if (!self::current()) { self::run(); }
    }

    private static function record(): Builder
    { return Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key', self::KEY); }

    private static function state(): string
    {
        $ran = app(Migrator::class)->getRepository()->getRan(); sort($ran);
        $domains = array_values(array_filter($ran, static fn ($name) => str_contains(strtolower($name), 'domains')));
        if ($domains !== [Registry::BASELINE]) {
            throw new \RuntimeException('Retained Domains migration history is incomplete.');
        }
        return json_encode(['version'=>Registry::version(),'migrations'=>$ran,'schema'=>SchemaVerifier::fingerprint()], JSON_THROW_ON_ERROR);
    }
}
