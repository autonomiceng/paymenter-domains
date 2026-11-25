<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class MoneyWorkSchema extends TableSchema
{
    public const TABLE='domains_money_work';

    protected static function definitions(): array
    {
        return [
            'domains_money_work' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id');
                $t->string('account_fingerprint', 64);
                $t->string('kind', 32);
                $t->unsignedBigInteger('subject_id');
                $t->unsignedBigInteger('revision');
                $t->unsignedBigInteger('turn');
                $t->unique(['server_id', 'kind', 'subject_id'], $t->getTable().'_subject_u');
                $t->index(['server_id', 'turn'], $t->getTable().'_turn_i');
            },
        ];
    }
}
