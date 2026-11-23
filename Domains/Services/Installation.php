<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Server,Setting};
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\{Cache,DB,Schema};
/** No destructive rollback or automatic adoption of retained history. */
final class Installation
{
    public const BASELINE = '2026_11_23_000001_create_domains_schema';
    public const SCHEMAS = [
        FoundationSchema::class,
        SalesPolicySchema::class,
        ContactProfileSchema::class,
        ObservationSchema::class,
        ReminderSchema::class,
        RuntimeSchema::class,
        PslSchema::class,
        PslReviewSchema::class,
        WatchSchema::class,
        AdoptionSchema::class,
        HistoryLinkSchema::class,
        WatchDnsSchema::class,
        NativeIntentSchema::class,
        BalanceSchema::class,
        ManagedPublicWorkSchema::class,
        ManagedPublicSchema::class,
        WatchScheduleSchema::class,
        WatchNoticeSchema::class,
        StaffWatchSchema::class,
        StaffAuditSchema::class,
        EnomTestSendLimitSchema::class,
        UserDeletionSchema::class,
        EnomLiveCallSchema::class,
        StaffAlertSchema::class,
        BalanceAlertSchema::class,
        ProfileContactSchema::class,
        ComplimentaryAccountSchema::class,
        BusinessSettingsSchema::class,
        RetentionSchema::class,
        MoneyWorkSchema::class,
    ];
    public static function legacy(): void
    {
        foreach(Server::where('extension','Enom')->get() as $server){
            // Server extensions boot even when their native enabled switch is off.
            $value=$server->settings()->where('key','operations_enabled')->first()?->value;
            if(filter_var($value,FILTER_VALIDATE_BOOLEAN)){throw new \DomainException('Pause processing on every legacy Enom server before installing or enabling Domains.');}
        }
    }
    public const VERIFIED_KEY='domains_schema_verified';
    private static function verificationRecord(): \Illuminate\Database\Eloquent\Builder
    { return Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key',self::VERIFIED_KEY); }
    public static function tables(): array
    {
        $prefix = DB::connection()->getTablePrefix(); $tables = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (str_starts_with($table, $prefix.'domains_')) { $tables[] = substr($table, strlen($prefix)); }
        }
        sort($tables); return $tables;
    }

    public static function expectedTables(): array
    {
        $allowed = [];
        foreach (self::SCHEMAS as $schema) {
            array_push($allowed, ...$schema::tables());
        }
        sort($allowed);
        return $allowed;
    }
    public function verify(): void
    {
        // Invalidate first: a failed explicit check must not leave an older success usable.
        self::verificationRecord()->delete();
        $version=self::verificationState();
        if (self::tables() !== self::expectedTables()) {
            throw new \RuntimeException('Retained Domains table inventory differs from the installed schema.');
        }
        SchemaVerifier::batch(static function():void {foreach(self::SCHEMAS as $schema){$schema::verify();}});
        self::verificationRecord()->create(['key'=>self::VERIFIED_KEY,'settingable_type'=>null,'settingable_id'=>null,
            'type'=>'string','encrypted'=>false,'value'=>$version]);
    }
    /** Identify the shipped package, or the schema definitions when installing directly from source. */
    public static function schemaVersion(): string
    {
        $manifest = dirname(__DIR__).'/manifest.json';
        if (is_file($manifest)) {
            return hash_file('sha256', $manifest) ?: throw new \RuntimeException('Cannot read the Domains package manifest.');
        }

        $files = [dirname(__DIR__).'/database/migrations/'.self::BASELINE.'.php'];
        foreach ([self::class, TableSchema::class, SchemaVerifier::class, ...self::SCHEMAS] as $class) {
            $files[] = (new \ReflectionClass($class))->getFileName();
        }
        $hash = hash_init('sha256');
        foreach ($files as $file) {
            if (!hash_update_file($hash, $file)) {
                throw new \RuntimeException('Cannot read the Domains schema definition.');
            }
        }
        return hash_final($hash);
    }

    private static function verificationState(): string
    {
        $ran=app(Migrator::class)->getRepository()->getRan();sort($ran);
        $domains = array_values(array_filter($ran, static fn ($name) => str_contains(strtolower($name), 'domains')));
        if ($domains !== [self::BASELINE]) {
            throw new \RuntimeException('Retained Domains migration history is incomplete.');
        }
        return json_encode(['version'=>self::schemaVersion(),'migrations'=>$ran,'schema'=>SchemaVerifier::fingerprint()],JSON_THROW_ON_ERROR);
    }
    public function verifyForOrders(): void
    {
        $records=self::verificationRecord()->get();
        if($records->count()===1){
            try{if($records->sole()->value===self::verificationState()){return;}}
            catch(\RuntimeException){} // A failed metadata read or missing migration still takes the full refusal path.
        }
        $this->verify();
    }
    public function install(): void
    {
        OperationClaims::outside(); self::legacy();
        if (FreshInstallation::uninstalled()) { Runtime::register(); FreshInstallation::install(); SiteDnsDefault::ensure(); return; }
        FreshInstallation::requireCompatible();
        Cache::lock('domains-installation',60)->block(5,function():void {
            if (!DB::table('migrations')->where('migration', self::BASELINE)->exists()) {
                throw new \DomainException('Retained Domains data has no installation record.');
            }
            $this->verify();
            ReminderTemplates::ensure();WatchNoticeTemplates::ensure();
        });
        SiteDnsDefault::ensure();
        \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(\Paymenter\Extensions\Servers\Domains\Catalogue\Writer::class)?->republishRetailRule();
    }
}
