<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Installation;

use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\Pricing\PriceSchema;
use Paymenter\Extensions\Servers\Domains\Catalogue\Publication\OfferSchema;
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema,BalanceAlertSchema,BalanceSchema,BusinessSettingsSchema,
    ComplimentaryAccountSchema,ContactProfileSchema,DnsTemplateClaimsSchema,DnsTemplatesSchema,DomainAuditSchema,
    EnomLiveCallSchema,EnomTestSendLimitSchema,FoundationSchema,HistoryLinkSchema,MoneyWorkSchema,NativeIntentSchema,ObservationSchema,
    ProfileContactSchema,ReminderSchema,RetentionSchema,RuntimeSchema,StaffAlertSchema,StaffAuditSchema,
    UserDeletionSchema};
use Paymenter\Extensions\Servers\Domains\Storefront\Search\{DnsQuotaSchema,RdapQuotaSchema};

/** The one ordered list of feature-owned table definitions and the identity of the code that defines them. */
final class Registry
{
    public const BASELINE = '2026_11_23_000001_create_domains_schema';
    /** Install order. Each feature owns its definition; this list owns order and the complete table inventory. */
    public const SCHEMAS = [
        FoundationSchema::class,
        OfferSchema::class,
        PriceSchema::class,
        ContactProfileSchema::class,
        ObservationSchema::class,
        ReminderSchema::class,
        RuntimeSchema::class,
        DomainAuditSchema::class,
        AdoptionSchema::class,
        HistoryLinkSchema::class,
        DnsTemplatesSchema::class,
        NativeIntentSchema::class,
        BalanceSchema::class,
        DnsQuotaSchema::class,
        RdapQuotaSchema::class,
        DnsTemplateClaimsSchema::class,
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

    /** Domains tables present on the connection, without the connection prefix. */
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

    /** Identify the shipped package, or the schema definitions when installing directly from source. */
    public static function version(): string
    {
        $root = dirname(__DIR__);
        if (is_file($root.'/manifest.json')) {
            return hash_file('sha256', $root.'/manifest.json') ?: throw new \RuntimeException('Cannot read the Domains package manifest.');
        }

        $files = [$root.'/database/migrations/'.self::BASELINE.'.php'];
        foreach ([self::class, Verification::class, TableSchema::class, SchemaVerifier::class, ...self::SCHEMAS] as $class) {
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
}
