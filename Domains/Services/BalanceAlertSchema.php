<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class BalanceAlertSchema extends TableSchema
{
    public const TABLE='domains_balance_alerts';

    protected static function definitions(): array
    {
        return [
            'domains_balance_alerts' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id');
                $t->string('account_fingerprint', 64);
                $t->string('pending_day', 10)->nullable();
                $t->unsignedBigInteger('retry_at')->default(0);
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->string('claim', 32)->nullable();
                $t->unique(['server_id', 'account_fingerprint']);
            },
        ];
    }
}
