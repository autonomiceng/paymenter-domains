<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Derived display candidacy only; execution still requires the original encrypted evidence. */
final class ManagementProgressIndex
{
    public const MIGRATION='2026_11_02_000047_index_management_progress';
    public static function definition(Blueprint $table): void
    {
        $table->boolean('management_pending')->nullable();
        $table->index(['domain_id','authority_generation','account_fingerprint','action','management_pending','id'],$table->getTable().'_management_progress_i');
    }
    public static function capture(Operation $op): void
    {
        // Historical installations acquire this column through the explicit upgrade.
        if (in_array($op->action,ManagementProgress::ACTIONS,true) && Schema::hasColumn('domains_operations','management_pending')) {
            $op->management_pending=self::pending($op);
        }
    }
    private static function pending(Operation $op): bool
    {
        try {
            if (!isset($op->policy['management'])) { return false; }
            $d=Domain::find($op->domain_id);
            return !$d || ManagementProgress::pending($op,$d);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) { return true; }
    }
    public static function install(): void
    {
        FoundationSchema::verify();
        if (!Schema::hasColumn('domains_operations','management_pending')) {
            Schema::table('domains_operations',self::definition(...));
        }
        // Classify retained rows once, under each row lock; never rewrite request evidence or dates.
        DB::table('domains_operations')->whereIn('action',ManagementProgress::ACTIONS)->whereNull('management_pending')->select('id')->chunkById(100,function($rows):void {
            foreach ($rows as $row) {
                DB::transaction(function()use($row):void {
                    $op=Operation::whereKey($row->id)->whereNull('management_pending')->lockForUpdate()->first();
                    if ($op) { DB::table('domains_operations')->where('id',$op->id)->update(['management_pending'=>self::pending($op)]); }
                });
            }
        });
        self::verify();
    }
    public static function upgrade(): void
    {
        if (!Schema::hasColumn('domains_operations','management_pending')) { throw new \RuntimeException('Management progress storage is missing.'); }
        self::install();
    }
    public static function verify(): void
    {
        if (!Schema::hasColumn('domains_operations','management_pending')) { throw new \RuntimeException('Management progress storage is missing.'); }
        FoundationSchema::verify();
        if (DB::table('domains_operations')->whereIn('action',ManagementProgress::ACTIONS)->whereNull('management_pending')->exists()) {
            throw new \RuntimeException('Management progress backfill is incomplete.');
        }
    }
}
