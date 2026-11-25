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

    /** Package identity and repaired physical shape from the last complete check in this process, before its migration was recorded. */
    private static ?array $checked = null;

    public static function run(): void
    {
        self::check();
        self::finish();
    }

    /** Check and repair every table without recording success; the baseline migration calls this before Laravel records it. */
    public static function check(?string $version = null): void
    {
        // Invalidate first: a failed explicit check must not leave an older success usable.
        self::$checked = null; self::record()->delete();
        // Read before the check, or keep the identity a schema-only retry already checked: a later swap then fails current().
        $version ??= Registry::version();
        if (Registry::tables() !== Registry::expectedTables()) {
            throw new \RuntimeException('Retained Domains table inventory differs from the installed schema.');
        }
        SchemaVerifier::batch(static function (): void { foreach (Registry::SCHEMAS as $schema) { $schema::verify(); } });
        self::$checked = ['version'=>$version, 'schema'=>SchemaVerifier::fingerprint()];
    }

    /** Record exactly what the full check saw; an unchecked or changed shape takes the full check first. */
    public static function finish(): void
    {
        $live = self::live();
        if (self::$checked !== null && self::$checked['version'] !== $live['version']) {
            // Schema classes loaded before a package swap cannot check the new package; leave no record so a fresh request does.
            self::$checked = null;
            throw new \RuntimeException('The Domains package changed during verification. Verify the installation again.');
        }
        if (self::$checked === null || self::$checked['schema'] !== $live['schema']) { self::check(self::$checked['version'] ?? null); }
        $checked = self::$checked; self::$checked = null; self::record()->delete();
        self::record()->create(['key'=>self::KEY,'settingable_type'=>null,'settingable_id'=>null,
            'type'=>'string','encrypted'=>false,'value'=>self::state($checked)]);
    }

    /** True only while the single record matches this package, migration history and physical schema. */
    public static function current(): bool
    {
        $records = self::record()->get();
        if ($records->count() !== 1) { return false; }
        try { return $records->sole()->value === self::state(self::live()); }
        catch (\RuntimeException) { return false; } // A failed metadata read or missing migration takes the full refusal path.
    }

    public static function forOrders(): void
    {
        if (!self::current()) { self::run(); }
    }

    private static function record(): Builder
    { return Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key', self::KEY); }

    /** @return array{version: string, schema: string} */
    private static function live(): array
    { return ['version'=>Registry::version(), 'schema'=>SchemaVerifier::fingerprint()]; }

    /** @param array{version: string, schema: string} $checked */
    private static function state(array $checked): string
    {
        $ran = app(Migrator::class)->getRepository()->getRan(); sort($ran);
        $domains = array_values(array_filter($ran, static fn ($name) => str_contains(strtolower($name), 'domains')));
        if ($domains !== [Registry::BASELINE]) {
            throw new \RuntimeException('Retained Domains migration history is incomplete.');
        }
        return json_encode(['version'=>$checked['version'],'migrations'=>$ran,'schema'=>$checked['schema']], JSON_THROW_ON_ERROR);
    }
}
