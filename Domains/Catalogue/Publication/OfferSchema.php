<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Catalogue\Publication;

// Column declarations adapted from Enom d2c55e2.
use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

/** Offer listing intent, publication revision and the selected seller per ending. */
final class OfferSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_tlds' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id');
                $t->string('registrar_key', 32);
                $t->string('account_fingerprint', 64);
                $t->string('tld', 63);
                $t->boolean('use_default_rule')->default(true);
                $t->string('round_up', 16)->default('none');
                $t->string('pricing_mode', 32)->default('markup_percent');
                $t->decimal('markup', 12, 2)->nullable();
                $t->decimal('fixed_register', 12, 2)->nullable();
                $t->decimal('fixed_renew', 12, 2)->nullable();
                $t->decimal('fixed_transfer', 12, 2)->nullable();
                $t->unsignedInteger('sort_order')->default(100);
                $t->json('registry_fields')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->json('catalogue_intent')->nullable();
                $t->json('catalogue_facts')->nullable();
                $t->unsignedBigInteger('catalogue_revision')->default(0);
                $t->json('catalogue_published_revision')->nullable();
                $t->decimal('catalogue_retained_annual_retail', 12, 2)->nullable();
                $t->boolean('catalogue_created')->default(false);
                $t->json('catalogue_issues')->nullable();
                $t->unique(['server_id', 'tld']);
            },
            'domains_sales_policies' => static function (Blueprint $t): void {
                $t->id();
                $t->string('tld', 63)->unique();
                $t->unsignedBigInteger('server_id')->nullable();
                $t->string('registrar_key', 32)->nullable();
                $t->string('account_fingerprint', 64)->nullable();
                $t->unsignedBigInteger('revision')->default(1);
                $t->boolean('register_enabled')->default(false);
                $t->boolean('transfer_enabled')->default(false);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            },
        ];
    }
}
