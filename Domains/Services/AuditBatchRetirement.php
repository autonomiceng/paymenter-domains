<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
/** Import verification precedes removal. Reruns never delete event rows. */
final class AuditBatchRetirement
{
    public const MIGRATION='2026_11_11_000063_retire_audit_batches';
    public static function install(): void
    {
        AuditEvents::import();
        // Installation is an offline maintenance boundary: no batch writers run during schema changes.
        DB::transaction(static function():void {
            DB::table(AuditArchives::TABLE)->whereNull('event_key')->delete();
            DB::table('settings')->where('settingable_type',(new Server)->getMorphClass())
                ->whereIn('key',array_keys(AuditEvents::SETTINGS))->delete();
        });
        if(Schema::hasColumn('domains_reminder_schedules','audit')){
            Schema::table('domains_reminder_schedules',static fn(Blueprint $t)=>$t->dropColumn('audit'));
        }
        self::verify();
    }
    public static function verify(): void
    {
        AuditEventSchema::verify();
        if(Schema::hasColumn('domains_reminder_schedules','audit')||DB::table(AuditArchives::TABLE)->whereNull('event_key')->exists()){
            throw new \RuntimeException('Retained audit batch retirement is incomplete.');
        }
        ReminderSchema::verify();
    }
}
