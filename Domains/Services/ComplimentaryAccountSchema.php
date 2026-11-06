<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class ComplimentaryAccountSchema
{
    public const MIGRATION='2026_11_05_000055_create_complimentary_accounts';
    private static function accounts(Blueprint $t):void
    {
        $t->id();$t->unsignedBigInteger('user_id');$t->boolean('enabled');$t->unsignedBigInteger('actor_id');
        $t->string('reason',500);$t->dateTime('created_at');$t->index(['user_id','id']);
    }
    private static function approvals(Blueprint $t):void
    {
        $t->id();$t->unsignedBigInteger('operation_id');$t->string('amount',32);$t->unsignedBigInteger('actor_id');
        $t->string('reason',500);$t->dateTime('created_at');$t->index(['operation_id','id']);
    }
    private static function definitions():array {return ['domains_complimentary_accounts'=>self::accounts(...),'domains_complimentary_approvals'=>self::approvals(...)];}
    public static function install():void
    {foreach(self::definitions() as $name=>$definition){if(!Schema::hasTable($name)){Schema::create($name,$definition);}}self::verify();}
    public static function verify():void {foreach(self::definitions() as $name=>$definition){SchemaVerifier::verify($name,$definition);}}
}
