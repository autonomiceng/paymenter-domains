<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** New instants are UTC. Retained wall times lack historical site-zone provenance and stay unmarked. */
final class WatchAuditTimeSchema
{
    public const MIGRATION='2026_11_11_000060_add_watch_audit_time_zone';
    public static function install(): void
    {
        PslReviewSchema::verify();
        if(!Schema::hasColumn('domains_watch_audit','time_zone')){
            Schema::table('domains_watch_audit',static fn(Blueprint $t)=>$t->string('time_zone',64)->nullable());
        }
        self::verify();
    }
    public static function verify(): void
    {
        if(!Schema::hasColumn('domains_watch_audit','time_zone')){throw new \RuntimeException('Retained watch audit time zones are missing.');}
        PslReviewSchema::verify();
    }
}
