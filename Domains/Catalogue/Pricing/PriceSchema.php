<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Catalogue\Pricing;

// Column declarations adapted from Enom d2c55e2.
use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Services\TableSchema;

/** Registrar cost observations and published retail per exact account, ending, action and term. */
final class PriceSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_prices' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id');
                $t->string('registrar_key', 32);
                $t->string('account_fingerprint', 64);
                $t->string('tld', 63);
                $t->string('action', 32);
                $t->unsignedSmallInteger('term');
                $t->string('currency', 3);
                $t->unsignedBigInteger('revision')->default(1);
                $t->decimal('wholesale', 20, 8)->nullable();
                $t->decimal('retail', 20, 8)->nullable();
                $t->string('unit', 32);
                $t->string('source', 128);
                $t->dateTime('observed_at');
                $t->timestamp('fresh_until')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->boolean('catalogue_created')->default(false);
                $t->boolean('cost_enabled')->nullable();
                $t->string('cost_source_unit', 32)->nullable();
                $t->unique(['server_id', 'tld', 'action', 'term', 'currency'], $t->getTable().'_price_unique');
            },
        ];
    }
}
