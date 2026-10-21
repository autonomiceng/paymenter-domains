<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Additive retained replay keys and encrypted audit archives. */
final class StaffAuditSchema
{
    public const MIGRATION='2026_10_21_000034_create_staff_audit_storage';
    public static function tables(): array { return ['domains_staff_review_keys','domains_audit_archives']; }
    private static function keys(Blueprint $table): void
    {
        $table->unsignedBigInteger('server_id');
        $table->string('review_key',32);
        $table->unsignedBigInteger('expires_at');
        $table->primary(['server_id','review_key']);
    }
    private static function archives(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('server_id');
        $table->string('family',32);
        $table->text('payload');
        $table->index(['server_id','family','id']);
    }
    public static function install(): void
    {
        if(array_filter(array_map(Schema::hasTable(...),self::tables()))){self::verify();return;}
        Schema::create('domains_staff_review_keys',self::keys(...));
        Schema::create('domains_audit_archives',self::archives(...));
        self::verify();
    }
    public static function verify(): void
    {
        SchemaVerifier::verify('domains_staff_review_keys',self::keys(...));
        SchemaVerifier::verify('domains_audit_archives',self::archives(...));
    }
}
