<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Retain total calls/spend; rows predating the purpose marker are all other work. */
final class LiveCatalogueSchema
{
    public const MIGRATION='2026_11_04_000054_add_live_catalogue_usage';
    public static function install(): void
    {
        LiveReservationSchema::verify();
        if(!Schema::hasColumn(LiveReservationSchema::TABLE,'catalogue_used')){
            Schema::table(LiveReservationSchema::TABLE,static fn(Blueprint $t)=>$t->unsignedBigInteger('catalogue_used')->default(0));
        }
        self::verify();
    }
    public static function verify(): void
    {
        if(!Schema::hasColumn(LiveReservationSchema::TABLE,'catalogue_used')){throw new \RuntimeException('Retained Live catalogue usage is missing.');}
        LiveReservationSchema::verify();
    }
}
