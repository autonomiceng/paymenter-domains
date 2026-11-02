<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** No daemon required: finish the response first, then enter the same durable engine. Cron recovers interruptions. */
final class ManagementStart
{
    public static function afterResponse(Operation $op, DomainManagement $engine, ManagementProtocol $driver): void
    {
        DB::afterCommit(static function()use($op,$engine,$driver):void {
            app()->terminating(static function()use($op,$engine,$driver):void {
                try { $engine->run((int)$op->id,$driver,RequestOrigin::Interactive,75); }
                catch (\Throwable $error) { Log::warning('Domains immediate management start deferred.',['operation_id'=>$op->id,'exception_class'=>$error::class]); }
            });
        });
    }
}
