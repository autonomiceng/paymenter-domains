<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Server, Setting, NotificationTemplate};
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\{Cache, DB, Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, OfferState, UpgradeGate};

/** The extension lifecycle owns new-install authority; schema maintenance alone never grants it. */
final class FreshInstallation
{
    private static ?self $owner = null;
    private function __construct(private readonly string $intent, private readonly string $manifest) {}
    private static function query(): \Illuminate\Database\Eloquent\Builder
    { return Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key', UpgradeGate::KEY); }
    private static function files(): array
    { return app(Migrator::class)->getMigrationFiles([dirname(__DIR__).'/database/migrations']); }

    private static function manifest(): string
    {
        $root = dirname(__DIR__); $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(),strlen($root)+1)] = hash_file('sha256',$file->getPathname());
            }
        }
        ksort($files); return hash('sha256', OfferState::json($files));
    }

    public static function install(): void
    {
        OperationClaims::outside(); Installation::legacy();
        Cache::lock('domains-fresh-installation', 60)->block(5, static function (): void {
            // MariaDB DDL commits transactions. A connection lock survives both DDL and cache TTL expiry.
            $connection = DB::connection(); $mysql = in_array($connection->getDriverName(), ['mysql','mariadb'], true);
            $lock = hash('sha256', 'domains-install-'.$connection->getDatabaseName());
            if ($mysql && (int) $connection->selectOne('SELECT GET_LOCK(?, 0) AS owned', [$lock])->owned !== 1) {
                throw new \DomainException('Another Domains initializer is running. Retry installation.');
            }
            try { self::run(); }
            finally { if ($mysql) { $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]); } }
        });
    }

    private static function run(): void
    {
        $manifest = self::manifest();
        $owner = DB::transaction(static function () use ($manifest): ?self {
            // Lock the global settings range before proving absence or creating the durable intent.
            Setting::orderBy('id')->lockForUpdate()->get();
            $rows = self::query()->lockForUpdate()->get();
            if ($rows->isNotEmpty()) {
                $state = UpgradeGate::initializationState();
                if ($state['state'] !== 'initializing') { return null; }
                if ($state['manifest'] !== $manifest) { throw new \DomainException('Domains initialization package changed. Restore the initializing package.'); }
                return new self($state['intent'], $manifest);
            }
            $ran = app(Migrator::class)->getRepository()->getRan();
            $history = array_intersect($ran, array_keys(self::files()));
            if ($history !== [] || self::tables() !== []) { return null; } // Retained C6 preflight/activation remain authoritative.
            self::empty(true);
            $intent = bin2hex(random_bytes(16));
            $row = new Setting; $row->forceFill(['key'=>UpgradeGate::KEY,'type'=>'string','encrypted'=>true,
                'value'=>OfferState::json(['state'=>'initializing','generation'=>1,'intent'=>$intent,'manifest'=>$manifest])])->save();
            return new self($intent, $manifest);
        });
        if ($owner === null) { app(Installation::class)->install(); return; }
        self::$owner = $owner;
        try {
            $owner->check();
            app(Migrator::class)->run([dirname(__DIR__).'/database/migrations'], ['force'=>true]);
            app(Installation::class)->verify(); ReminderTemplates::ensure(); WatchNoticeTemplates::ensure();
            UpgradeGate::initialize($owner);
        } finally { self::$owner = null; CallerRouting::reset(); }
    }

    private static function tables(): array
    {
        $prefix = DB::connection()->getTablePrefix(); $tables = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (str_starts_with($table, $prefix.'domains_')) { $tables[] = substr($table, strlen($prefix)); }
        }
        sort($tables); return $tables;
    }

    private static function empty(bool $new = false): void
    {
        if (Server::where('extension','Domains')->exists()
            || Setting::where('key','like','domains%')->where(static fn ($q) => $q->where('key','!=',UpgradeGate::KEY)
                ->orWhereNotNull('settingable_type')->orWhereNotNull('settingable_id'))->exists()
            || $new && NotificationTemplate::where('key','like','domains%')->exists()) {
            throw new \DomainException('Retained Domains settings or identity refuse fresh initialization. Restore and verify installation history.');
        }
        foreach (self::tables() as $table) {
            $query = DB::table($table)->lockForUpdate();
            if ($table === 'domains_psl_status') {
                foreach ($query->get() as $row) {
                    if ((int)$row->id !== 1 || (int)$row->consecutive_failures !== 0
                        || array_filter((array)$row, static fn ($v, $k) => !in_array($k,['id','consecutive_failures'],true) && $v !== null, ARRAY_FILTER_USE_BOTH)) {
                        throw new \DomainException('Domains initialization has retained suffix activity.');
                    }
                }
            } elseif ($query->exists()) { throw new \DomainException('Domains initialization is no longer empty. Keep it paused and investigate.'); }
        }
    }

    /** Partial DDL is repairable only inside the owning lifecycle invocation, never by retained installers. */
    public static function recovering(): bool
    { if (self::$owner === null) { return false; } self::$owner->check(); return true; }

    /** Capability check: only the current lifecycle invocation can complete its proven durable intent. */
    public function check(bool $complete = false): array
    {
        if (self::$owner !== $this) { throw new \LogicException('Owned initialization required.'); }
        $state = UpgradeGate::initializationState();
        if ($state['state'] !== 'initializing' || $state['generation'] !== 1 || $state['intent'] !== $this->intent || $state['manifest'] !== $this->manifest) {
            throw new \DomainException('Domains initialization intent changed.');
        }
        self::empty();
        if ($complete && (self::manifest() !== $this->manifest
            || array_diff(array_keys(self::files()), DB::table('migrations')->orderBy('id')->lockForUpdate()->pluck('migration')->all()))) {
            throw new \DomainException('Domains initialization source or migration history changed.');
        }
        return $state;
    }
}
