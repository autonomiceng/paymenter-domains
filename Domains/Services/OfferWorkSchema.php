<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive dormant queue state, with no extra catalogue/fill/metadata table. */
final class OfferWorkSchema
{
    public const MIGRATION = '2026_11_02_000049_add_offer_work';
    private const COLUMNS = [
        'domains_observation_queues' => ['offer_work' => 'json', 'offer_claim' => 'hash', 'offer_lease' => 'revision', 'offer_turn' => 'revision'],
    ];
    public static function retained(Blueprint $table, string $name): void
    {
        foreach (self::COLUMNS[$name] ?? [] as $column => $type) {
            if (Schema::hasColumn($name, $column)) { self::column($table, $column, $type); }
        }
    }
    private static function column(Blueprint $t, string $name, string $type): void
    {
        match ($type) {
            'json' => $t->json($name)->nullable(),
            'hash' => $t->string($name, 64)->nullable(),
            'revision' => $t->unsignedBigInteger($name)->default(0),
        };
    }
    public static function install(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $type) {
                if (!Schema::hasColumn($table, $column)) { Schema::table($table, static fn (Blueprint $t) => self::column($t, $column, $type)); }
            }
        }
        self::verify();
    }
    public static function verify(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $_) {
                if (!Schema::hasColumn($table, $column)) { throw new \RuntimeException('Missing retained offer work storage.'); }
            }
        }
        FoundationSchema::verify(); ObservationSchema::verify();
    }
}
