<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive staging only. No conversion or activation runs during installation. */
final class OfferSchema
{
    public const MIGRATION = '2026_11_02_000048_add_offer_conversion';
    public const COLUMNS = [
        'domains_accounts' => ['catalogue_defaults' => 'json'],
        'domains_tlds' => ['catalogue_intent' => 'json', 'catalogue_facts' => 'json',
            'catalogue_revision' => 'revision', 'catalogue_published_revision' => 'json',
            'catalogue_retained_annual_retail' => 'money', 'catalogue_converted' => 'bool',
            'catalogue_created' => 'bool', 'catalogue_issues' => 'json'],
        'domains_prices' => ['catalogue_observation' => 'json', 'catalogue_created' => 'bool'],
        'domains_observation_queues' => ['offer_conversion_cursor' => 'cursor',
            'offer_conversion_source' => 'hash', 'offer_conversion_complete' => 'bool',
            'offer_feed_run' => 'revision', 'offer_feed_tried_at' => 'time',
            'offer_feed_succeeded_at' => 'time', 'offer_feed_failed_at' => 'time',
            'offer_feed_failure' => 'hash', 'offer_queue_created' => 'bool'],
    ];

    /** Include exactly the retained additive columns in the original schema verifiers. */
    public static function retained(Blueprint $table, string $name): void
    {
        foreach (self::COLUMNS[$name] as $column => $type) {
            if (Schema::hasColumn($name, $column)) { self::column($table, $column, $type); }
        }
        OfferWorkSchema::retained($table, $name);
    }

    private static function column(Blueprint $t, string $name, string $type): void
    {
        match ($type) {
            'json' => $t->json($name)->nullable(),
            'money' => $t->decimal($name, 12, 2)->nullable(),
            'bool' => $t->boolean($name)->default(false),
            'revision' => $t->unsignedBigInteger($name)->default(0),
            'cursor' => $t->string($name, 63)->default(''),
            'hash' => $t->string($name, 64)->nullable(),
            'time' => $t->unsignedBigInteger($name)->nullable(),
        };
    }

    public static function install(): void
    {
        // Per-column DDL is resumable even if a MySQL upgrade stops between ALTERs.
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $type) {
                if (!Schema::hasColumn($table, $column)) {
                    Schema::table($table, static fn (Blueprint $t) => self::column($t, $column, $type));
                }
            }
        }
        self::verify();
    }

    public static function verify(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $_) {
                if (!Schema::hasColumn($table, $column)) { throw new \RuntimeException('Missing retained offer storage.'); }
            }
        }
        FoundationSchema::verify();
        ObservationSchema::verify();
    }
}
