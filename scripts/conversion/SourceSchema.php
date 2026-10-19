<?php
// Enom b9b12e9 schema declarations; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SourceSchema {
    private const NATIVE=['extensions','products','roles','notification_templates','settings','jobs','audits'];
    public static function transactional(): void
    {
        $db=\Illuminate\Support\Facades\DB::connection();if($db->getDriverName()==='sqlite'){return;}
        if(!in_array($db->getDriverName(),['mysql','mariadb'],true)){throw new \DomainException('Unsupported native transactional database.');}
        $prefix=$db->getTablePrefix();$tables=array_map(fn($table)=>$prefix.$table,self::NATIVE);$marks=implode(',',array_fill(0,count($tables),'?'));$engines=[];
        foreach($db->select('SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.$marks.')',$tables)as$row){$engines[substr($row->name,strlen($prefix))]=$row->engine;}
        self::engines($engines);
    }
    /** Pure policy seam; production always reads information_schema above. */
    public static function engines(array $engines): void
    {
        $names=array_keys($engines);sort($names);$expected=self::NATIVE;sort($expected);
        if($names!==$expected||array_filter($engines,fn($engine)=>strtolower((string)$engine)!=='innodb')){throw new \DomainException('All native cutover tables must use InnoDB.');}
    }
    public static function verify(bool $fixture = false): void
    {
        self::table($fixture, 'enom_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('server_id');
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
        self::table($fixture, 'enom_operations', function (Blueprint $table) {
            $table->id();
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
            $table->string('provider_order_id', 128)->nullable();
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->string('funding_reason', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_item_id', 'action']);
        });
        self::table($fixture, 'enom_tlds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
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
    }

    private static function table(bool $fixture, string $name, \Closure $definition): void
    {
        if ($fixture) { Schema::create($name, $definition); return; }
        if (!Schema::hasTable($name)) { throw new \DomainException('Exact source schema required.'); }
        \Paymenter\Extensions\Servers\Domains\Services\SchemaVerifier::verify($name, $definition);
    }
}
