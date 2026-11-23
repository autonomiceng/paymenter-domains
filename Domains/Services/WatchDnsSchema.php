<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class WatchDnsSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_watch_profiles' => static function (Blueprint $t): void {
                $t->id();
                $t->string('name', 128);
                $t->json('nameservers');
                $t->unsignedBigInteger('revision')->default(1);
                $t->boolean('enabled')->default(false);
                $t->unsignedBigInteger('edited_by');
                $t->text('reason');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            },
        ];
    }
}
