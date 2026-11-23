<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

final class NativeIntentSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_native_intents' => static function (Blueprint $t): void {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    $t->bigIncrements('service_id');
                } else {
                    $t->unsignedBigInteger('service_id')->primary();
                }
                $t->string('kind', 32);
                $t->dateTime('created_at');
            },
        ];
    }
}
