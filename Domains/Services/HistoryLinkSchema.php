<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class HistoryLinkSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_history_links' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('domain_id');
                $t->unsignedBigInteger('service_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('invoice_id');
                $t->unsignedBigInteger('invoice_item_id')->nullable();
                $t->string('source', 32);
                $t->unsignedBigInteger('linked_by');
                $t->text('reason');
                $t->dateTime('linked_at');
                $t->longText('evidence');
                $t->string('link_key', 64)->unique();
                $t->index(['domain_id', 'id']);
            },
        ];
    }
}
