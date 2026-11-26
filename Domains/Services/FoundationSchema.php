<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

// Column declarations adapted from Enom d2c55e2.
use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\{Installer, TableSchema};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationSchema;

/** Holdings, renewal funding postings and accounts. Registry also lists OperationSchema on its own. */
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
            RenewalFundingLedger::TABLE => static function (Blueprint $t): void {
                $t->unsignedBigInteger('invoice_item_id');
                $t->unsignedTinyInteger('retry_number');
                $t->enum('source', ['item', 'payment']);
                $t->unsignedBigInteger('source_id');
                $t->bigInteger('amount_minor');
                $t->primary(['invoice_item_id', 'retry_number', 'source', 'source_id']);
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
        ];
    }

    /**
     * Fixtures and commerce install the holding foundation with its operations in one call; each part verifies its own
     * tables. The two inventories are checked together first, so retained foundation tables never get new empty operation tables.
     */
    public static function install(): void
    {
        self::requireWhole([...self::tables(), ...OperationSchema::tables()], Installer::recovering());
        parent::install();
        OperationSchema::install();
    }
}
