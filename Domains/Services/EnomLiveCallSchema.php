<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class EnomLiveCallSchema extends TableSchema
{
    public const TABLE = 'domains_enom_live_calls';

    protected static function definitions(): array
    {
        return [
            'domains_enom_live_calls' => static function (Blueprint $t): void {
                $t->string('account', 64);
                $t->string('day', 10);
                $t->unsignedBigInteger('used')->default(0);
                $t->unsignedBigInteger('spend_micros')->default(0);
                $t->unsignedBigInteger('catalogue_used')->default(0);
                $t->primary(['account', 'day']);
            },
        ];
    }
}
