<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class EnomTestSendLimitSchema extends TableSchema
{
    public const ACCOUNTS='domains_enom_test_send_accounts';
    public const SENDS='domains_enom_test_sends';

    protected static function definitions(): array
    {
        return [
            'domains_enom_test_send_accounts' => static function (Blueprint $t): void {
                $t->string('account', 64)->primary();
            },
            'domains_enom_test_sends' => static function (Blueprint $t): void {
                $t->id();
                $t->string('account', 64);
                $t->string('command', 32);
                $t->unsignedBigInteger('sent_at');
                $t->index(['account', 'sent_at']);
            },
        ];
    }
}
