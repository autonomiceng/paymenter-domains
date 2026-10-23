<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Retained native invoice associations, with no funding or settlement projection. */
final class HistoryLinkSchema
{
    private static function definition(Blueprint $t, bool $legacyDates = false): void
    {
        $t->id(); $t->unsignedBigInteger('domain_id'); $t->unsignedBigInteger('service_id');
        $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('invoice_id');
        $t->unsignedBigInteger('invoice_item_id')->nullable(); $t->string('source', 32);
        $t->unsignedBigInteger('linked_by'); $t->text('reason'); ($legacyDates ? $t->timestamp('linked_at') : $t->dateTime('linked_at'));
        $t->longText('evidence'); $t->string('link_key', 64)->unique();
    }
    public static function install(): void
    {
        if (!Schema::hasTable('domains_history_links')) { Schema::create('domains_history_links', self::definition(...)); }
        self::verify();
    }
    public static function verify(bool $legacyDates = false): void { SchemaVerifier::verify('domains_history_links', static fn (Blueprint $t) => self::definition($t, $legacyDates)); }
}
