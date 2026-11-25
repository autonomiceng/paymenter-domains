<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class BusinessSettingsSchema extends TableSchema
{
    public const TABLE='domains_business_settings';

    protected static function definitions(): array
    {
        return [
            'domains_business_settings' => static function (Blueprint $t): void {
                $t->enum('id', ['1'])->primary();
                $t->boolean('imported')->default(false);
                $t->longText('pricing_rule')->nullable();
                $t->longText('card_fee')->nullable();
                $t->longText('dns_template')->nullable();
            },
        ];
    }

    protected static function seed(): void
    {
        DB::table('domains_business_settings')->insertOrIgnore(['id' => 1, 'imported' => true]);
    }

    public static function verify(): void
    {
        parent::verify();
        if (DB::table('domains_business_settings')->count() !== 1 || !DB::table('domains_business_settings')->where('id', 1)->where('imported', true)->exists()) {
            throw new \RuntimeException('Missing domains_business_settings singleton.');
        }
    }
}
