<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Retained notification evidence. Gate39 owns migration/verification wiring. */
final class ReminderSchema
{
    private static function definitions(): array
    {
        return [
            'domains_reminder_schedules'=>static function(Blueprint $t):void {
                $t->id();$t->unsignedBigInteger('server_id')->unique();$t->string('account_fingerprint',64);
                $t->boolean('enabled')->default(false);$t->text('before_days');$t->text('after_days');
                $t->unsignedBigInteger('revision')->default(1);$t->text('audit');
                $t->unsignedBigInteger('cursor')->default(0);$t->unsignedBigInteger('turn')->default(0);
                $t->unsignedBigInteger('due_at')->default(0);
            },
            'domains_reminder_deliveries'=>static function(Blueprint $t):void {
                $t->id();$t->string('delivery_key',64)->unique();$t->unsignedBigInteger('domain_id')->index();
                $t->string('template',64);$t->string('state',24);$t->unsignedBigInteger('created_at');
                $t->unsignedBigInteger('accepted_at')->nullable();
            },
            'domains_obligation_alerts'=>static function(Blueprint $t):void {
                $t->id();$t->string('alert_key',64)->unique();$t->unsignedBigInteger('domain_id')->index();
                $t->unsignedBigInteger('generation');$t->string('code',40);$t->unsignedBigInteger('operation_id')->nullable();
                $t->unsignedBigInteger('first_seen');$t->unsignedBigInteger('last_seen');$t->unsignedBigInteger('resolved_at')->nullable();
            },
        ];
    }
    public static function install():void
    {
        $present=array_filter(array_keys(self::definitions()),fn($name)=>Schema::hasTable($name));
        if($present&&count($present)!==count(self::definitions())){throw new \RuntimeException('Partial retained reminder schema.');}
        foreach(self::definitions() as $name=>$definition){
            if(Schema::hasTable($name)){SchemaVerifier::verify($name,$definition);}else{Schema::create($name,$definition);}
        }
    }
    public static function verify():void
    {
        foreach(self::definitions() as $name=>$definition){
            if(!Schema::hasTable($name)){throw new \RuntimeException('Missing retained reminder schema.');}SchemaVerifier::verify($name,$definition);
        }
    }
}
