<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class StaffAlertSchema extends TableSchema
{
    public const TABLE='domains_staff_alert_receipts';

    protected static function definitions(): array
    {
        return [
            'domains_staff_alert_receipts' => static function (Blueprint $t): void {
                $t->string('delivery_key', 64)->primary();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('notification_id')->nullable();
            },
        ];
    }
}
