<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class StaffWatchSchema
{
    public const MIGRATION='2026_10_20_000033_create_watch_staff_claims';
    private static function claims(Blueprint $t): void
    { $t->string('claim',64)->primary();$t->string('subject_type',16);$t->unsignedBigInteger('subject_id');$t->string('action',16);$t->unsignedBigInteger('created_at');$t->index(['subject_type','subject_id','action','created_at'],'dws_'.substr(hash('sha256',$t->getTable()),0,16)); }
    public static function install(): void
    { if(!Schema::hasTable('domains_watch_staff_claims')){Schema::create('domains_watch_staff_claims',self::claims(...));}self::verify(); }
    public static function verify(): void{SchemaVerifier::verify('domains_watch_staff_claims',self::claims(...));}
}
