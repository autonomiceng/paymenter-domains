<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** The complimentary closure page keeps its place on the account queue row, apart from the paid refund cursor. */
final class ClosureCursorSchema
{
    public const MIGRATION='2026_11_09_000056_add_complimentary_closure_cursor';
    public const TABLE='domains_observation_queues';
    public const COLUMN='complimentary_cursor';
    public static function column(Blueprint $t): void { $t->unsignedBigInteger(self::COLUMN)->default(0); }
    public static function install(): void
    {
        ObservationSchema::verify();
        if(!Schema::hasColumn(self::TABLE,self::COLUMN)){Schema::table(self::TABLE,self::column(...));}
        self::verify();
    }
    public static function verify(): void
    {
        if(!Schema::hasColumn(self::TABLE,self::COLUMN)){throw new \RuntimeException('Retained complimentary closure cursor is missing.');}
        ObservationSchema::verify();
    }
}
