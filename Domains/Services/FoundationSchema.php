<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Column declarations adapted from Enom d2c55e2 migration; retained IDs and meanings.
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class FoundationSchema
{
    private const TABLES = ['domains_domains', 'domains_operations', 'domains_tlds', 'domains_accounts', 'domains_prices'];

    public static function install(): void
    {
        $present = array_filter(self::TABLES, static fn (string $table): bool => Schema::hasTable($table));
        if ($present !== [] && count($present) !== count(self::TABLES)) {
            throw new \RuntimeException('Partial retained Domains schema; restore and verify history before reinstall.');
        }
        self::table('domains_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            self::binding($table);
            $table->string('domain', 253)->index();
            $table->string('reservation_name', 253)->nullable()->unique();
            $table->string('sld', 63);
            $table->string('tld', 63);
            $table->string('status', 32);
            $table->date('provider_expires_at')->nullable();
            $table->string('provider_order_id', 128)->nullable();
            $table->longText('snapshot')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('provider_domain_id', 128)->nullable();
            $table->boolean('billing_managed')->default(true);
            $table->longText('renewal_policy')->nullable();
            $table->longText('observation')->nullable();
            $table->unsignedBigInteger('authority_generation')->default(0);
            $table->unsignedBigInteger('confirmed_revision')->default(0);
            $table->string('price_mode', 32)->default('standard');
            $table->longText('reminders')->nullable();
            $table->timestamps();
        });
        self::table('domains_operations', function (Blueprint $table) {
            $table->id();
            self::binding($table, true);
            $table->unsignedBigInteger('authority_generation')->nullable();
            $table->unsignedBigInteger('domain_id')->index();
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_item_id')->nullable();
            $table->string('operation_key', 191)->unique();
            $table->string('action', 32);
            $table->unsignedSmallInteger('term')->default(1);
            $table->string('funding_basis', 32)->default('paid_invoice');
            $table->string('state', 32)->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3);
            $table->date('base_expires_at')->nullable();
            $table->date('expected_expires_at')->nullable();
            $table->date('confirmed_expires_at')->nullable();
            $table->longText('payload')->nullable();
            $table->longText('policy')->nullable();
            $table->longText('cost')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->string('safe_code', 64)->nullable();
            $table->longText('attempt')->nullable();
            $table->longText('funding')->nullable();
            $table->string('provider_order_id', 128)->nullable();
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->string('funding_reason', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_item_id', 'action']);
        });
        self::table('domains_tlds', function (Blueprint $table) {
            $table->id();
            self::binding($table);
            $table->string('tld', 63);
            $table->boolean('enabled')->default(false);
            foreach (['register', 'renew', 'transfer'] as $action) { $table->boolean($action.'_enabled')->default(false); }
            $table->boolean('use_default_rule')->default(true);
            $table->string('round_up', 16)->default('none');
            $table->string('pricing_mode', 32)->default('markup_percent');
            $table->decimal('markup', 12, 2)->nullable();
            foreach (['register', 'renew', 'transfer'] as $action) { $table->decimal('fixed_'.$action, 12, 2)->nullable(); }
            $table->json('cost_register_terms')->nullable();
            $table->json('sale_holds')->nullable();
            $table->decimal('renew_price', 12, 2)->nullable();
            $table->decimal('cost_renew', 12, 4)->nullable();
            $table->decimal('cost_transfer', 12, 4)->nullable();
            $table->timestamp('costs_synced_at')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->unsignedTinyInteger('min_years')->default(1);
            $table->unsignedTinyInteger('max_years')->default(10);
            $table->json('registry_fields')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'tld']);
        });
        self::table('domains_accounts', function (Blueprint $table) {
            $table->id(); self::binding($table);
            $table->unique('server_id');
            $table->string('identity_fingerprint', 64)->unique();
            $table->string('environment', 16);
            $table->longText('stable_identity');
            $table->string('live_site', 255)->nullable();
            $table->boolean('processing_enabled')->default(false);
            $table->timestamps();
        });
        self::table('domains_prices', function (Blueprint $table) {
            $table->id(); self::binding($table);
            $table->string('tld', 63); $table->string('action', 32);
            $table->unsignedSmallInteger('term'); $table->string('currency', 3);
            $table->unsignedBigInteger('revision')->default(1);
            $table->decimal('wholesale', 20, 8)->nullable();
            $table->decimal('retail', 20, 8)->nullable();
            $table->string('unit', 32); $table->string('source', 128);
            $table->dateTime('observed_at'); $table->timestamp('fresh_until')->nullable();
            $table->json('eligibility')->nullable(); $table->string('hold_reason', 64)->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'tld', 'action', 'term', 'currency'], $table->getTable().'_price_unique');
        });
    }

    /** Call after Migrator on every native install/upgrade, even when migration history skips up(). */
    public static function verify(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) { throw new \RuntimeException('Incomplete retained Domains schema.'); }
        }
        self::install();
    }

    private static function binding(Blueprint $table, bool $historical = false): void
    {
        $table->unsignedBigInteger('server_id');
        $table->string('registrar_key', 32);
        $table->string('account_fingerprint', 64)->nullable($historical);
    }

    /** Verify retained tables against the same declarations, using an isolated empty reference. */
    private static function table(string $name, \Closure $definition): void
    {
        if (!Schema::hasTable($name)) { Schema::create($name, $definition); return; }
        SchemaVerifier::verify($name, $definition);
    }
}
