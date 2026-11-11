<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
/** Events reuse the encrypted archive store. Null event keys identify retained legacy batches. */
final class AuditEventSchema
{
    public const MIGRATION='2026_11_11_000062_add_audit_events';
    public static function columns(Blueprint $t): void
    {
        $t->string('event_key',64)->nullable();
        // Retained preflight accepts the staged column while this additive migration is pending.
        if(Schema::hasIndex(AuditArchives::TABLE,['event_key'],'unique')||DB::table('migrations')->where('migration',self::MIGRATION)->exists()){$t->unique('event_key');}
    }
    public static function install(): void
    {
        $present=Schema::hasColumn(AuditArchives::TABLE,'event_key');
        if(!$present){
            StaffAuditSchema::verify();
            Schema::table(AuditArchives::TABLE,static fn(Blueprint $t)=>$t->string('event_key',64)->nullable());
        }
        $recorded=DB::table('migrations')->where('migration',self::MIGRATION)->exists();
        // MariaDB can commit the column before the separate index statement. Resume only an unrecorded migration.
        if(!$recorded&&!Schema::hasIndex(AuditArchives::TABLE,['event_key'],'unique')){
            Schema::table(AuditArchives::TABLE,static fn(Blueprint $t)=>$t->unique('event_key'));
        }
        self::verify();
        AuditEvents::import();
    }
    public static function verify(): void
    {
        if(!Schema::hasColumn(AuditArchives::TABLE,'event_key')||!Schema::hasIndex(AuditArchives::TABLE,['event_key'],'unique')){throw new \RuntimeException('Missing retained audit event schema.');}
        StaffAuditSchema::verify();
    }
}
