<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

// Column declarations adapted from Enom d2c55e2.
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

final class FoundationSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_domains' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('service_id')->unique();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('server_id');
                $t->string('registrar_key', 32);
                $t->string('account_fingerprint', 64);
                $t->string('domain', 253)->index();
                $t->string('reservation_name', 253)->nullable()->unique();
                $t->string('sld', 63);
                $t->string('tld', 63);
                $t->string('status', 32);
                $t->date('provider_expires_at')->nullable();
                $t->string('provider_order_id', 128)->nullable();
                $t->longText('snapshot')->nullable();
                $t->json('capabilities')->nullable();
                $t->timestamp('last_synced_at')->nullable();
                $t->string('provider_domain_id', 128)->nullable();
                $t->boolean('billing_managed')->default(true);
                $t->longText('renewal_policy')->nullable();
                $t->longText('observation')->nullable();
                $t->unsignedBigInteger('authority_generation')->default(0);
                $t->unsignedBigInteger('confirmed_revision')->default(0);
                $t->string('price_mode', 32)->default('standard');
                $t->longText('reminders')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->string('provenance', 32)->nullable();
                $t->unsignedBigInteger('adoption_id')->nullable();
                $t->index(['server_id', 'id']);
            },
            'domains_operations' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id');
                $t->string('registrar_key', 32);
                $t->string('account_fingerprint', 64)->nullable();
                $t->unsignedBigInteger('authority_generation')->nullable();
                $t->unsignedBigInteger('domain_id')->index();
                $t->unsignedBigInteger('service_id')->index();
                $t->unsignedBigInteger('invoice_id')->nullable()->index();
                $t->unsignedBigInteger('invoice_item_id')->nullable();
                $t->string('operation_key', 191)->unique();
                $t->string('action', 32);
                $t->unsignedSmallInteger('term')->default(1);
                $t->string('funding_basis', 32)->default('paid_invoice');
                $t->string('state', 32)->index();
                $t->decimal('amount', 12, 2)->default(0);
                $t->string('currency', 3);
                $t->date('base_expires_at')->nullable();
                $t->date('expected_expires_at')->nullable();
                $t->date('confirmed_expires_at')->nullable();
                $t->longText('payload')->nullable();
                $t->longText('policy')->nullable();
                $t->longText('cost')->nullable();
                $t->string('request_fingerprint', 64)->nullable();
                $t->string('safe_code', 64)->nullable();
                $t->longText('attempt')->nullable();
                $t->longText('funding')->nullable();
                $t->string('provider_order_id', 128)->nullable();
                $t->unsignedBigInteger('authorized_by')->nullable();
                $t->string('funding_reason', 500)->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('lease_expires_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->boolean('management_pending')->nullable();
                $t->unsignedTinyInteger('retry_number')->default(0);
                $t->index(['server_id', 'account_fingerprint', 'id']);
                $t->index(['domain_id', 'authority_generation', 'account_fingerprint', 'action', 'management_pending', 'id'], $t->getTable().'_management_progress_i');
                $t->unique(['invoice_item_id', 'action', 'retry_number'], $t->getTable().'_debt_retry_u');
            },
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
                $t->boolean('catalogue_converted')->default(false);
                $t->boolean('catalogue_created')->default(false);
                $t->json('catalogue_issues')->nullable();
                $t->unique(['server_id', 'tld']);
            },
            'domains_accounts' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id');
                $t->string('registrar_key', 32);
                $t->string('account_fingerprint', 64);
                $t->string('identity_fingerprint', 64)->unique();
                $t->string('environment', 16);
                $t->longText('stable_identity');
                $t->string('live_site')->nullable();
                $t->boolean('processing_enabled')->default(false);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->json('catalogue_defaults')->nullable();
                $t->unique(['server_id']);
            },
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

    public static function verify(): void
    {
        parent::verify();
        if (DB::table('domains_operations')->whereIn('action', ManagementProgress::ACTIONS)->whereNull('management_pending')->exists()) {
            throw new \RuntimeException('Retained management operation has no progress state.');
        }
    }
}
