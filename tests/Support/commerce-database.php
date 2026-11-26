<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** Reuse installation work only while the native fixture is still completely untouched. */
function restoreCommerceDatabase(?string $operationSnapshot = null, ?array &$catalogue = null): bool
{
    if (class_exists(FixtureSetup::class, false)) { $fixtureTimer = new FixtureSetup; }
    $prefix = 'PAYMENTER_TEST_'.$operationSnapshot;
    $seeded = $operationSnapshot !== null && getenv($prefix.'_DATABASE');
    if (!$seeded) { $prefix = 'PAYMENTER_TEST_COMMERCE'; }
    $path = (string)getenv($prefix.'_DATABASE');
    $baseline=$GLOBALS['nativeFixtureDatabase']??null;
    $connection=Illuminate\Support\Facades\DB::connection();$pdo=$connection->getPdo();
    if($path===''||$baseline===null||$baseline['pdo']!==$pdo||$pdo->inTransaction()
        ||$baseline['changes']!==(int)$pdo->query('SELECT total_changes()')->fetchColumn()
        ||$baseline['schema']!==(int)$pdo->query('PRAGMA schema_version')->fetchColumn()
        ||config('audit.enabled')!==false||Carbon\Carbon::getTestNow()?->format(DATE_ATOM)!=='2026-10-18T12:00:00+00:00'
        ||app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->format(DATE_ATOM)!=='2026-10-18T12:00:00+00:00'){
        return false;
    }
    $sql=file_get_contents($path);
    same((string)getenv($prefix.'_SHA256'),hash('sha256',$sql),'Installed fixture snapshot retains admitted bytes');
    if ($seeded) {
        expect(preg_match('/^-- operation-catalogue: (.+)$/m', $sql, $state) === 1, 'Snapshot records the operation catalogue');
        $catalogue = json_decode($state[1], true, flags: JSON_THROW_ON_ERROR);
    }
    $objects=$pdo->query("SELECT type,name FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY type='view' DESC")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=OFF');
    foreach($objects as $object){$pdo->exec('DROP '.strtoupper($object['type']).' "'.str_replace('"','""',$object['name']).'"');}
    $pdo->exec($sql);$connection->useDefaultSchemaGrammar();
    expect(preg_match('/^-- booted-models: (.+)$/m',$sql,$models)===1,'Installed snapshot records migration model boot order');
    foreach(json_decode($models[1],true,512,JSON_THROW_ON_ERROR) as $model){
        expect(is_subclass_of($model,Illuminate\Database\Eloquent\Model::class),'Installed snapshot model is an Eloquent model');new $model;
    }
    Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
    Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
    if (class_exists(FixtureSetup::class, false)) { FixtureSetup::$restores++; }
    return true;
}

/** A single commerce setup may reuse installation prepared before its staff records are written. */
final class InstalledCommerceFixture
{
    private bool $consumed = false;

    private function __construct(private PDO $pdo, private ?int $schema, public readonly ?array $catalogue) {}

    public static function prepare(?string $operationSnapshot = null): self
    {
        if (class_exists(FixtureSetup::class, false)) { $fixtureTimer = new FixtureSetup; }
        if (!app()->bound(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)) {
            app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,
                new Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable('2026-10-18T12:00:00Z')));
        }
        // Installation publishes through the registered runtime, including on the fallback path.
        if (!app()->bound(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class)) {
            Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
        }
        $catalogue = null;
        if (!restoreCommerceDatabase($operationSnapshot, $catalogue)) {
            (new Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
            if (class_exists(FixtureSetup::class, false)) { FixtureSetup::$fallbacks++; }
        }
        $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
        $schema = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? (int) $pdo->query('PRAGMA schema_version')->fetchColumn() : null;
        return new self($pdo, $schema, $catalogue);
    }

    public function consume(): void
    {
        if (class_exists(FixtureSetup::class, false)) { $fixtureTimer = new FixtureSetup; }
        if ($this->consumed || Illuminate\Support\Facades\DB::connection()->getPdo() !== $this->pdo
            || $this->pdo->inTransaction() || ($this->schema !== null && $this->schema !== (int) $this->pdo->query('PRAGMA schema_version')->fetchColumn())) {
            throw new LogicException('Installed commerce fixture context is no longer valid.');
        }
        $this->consumed = true;
    }
}
