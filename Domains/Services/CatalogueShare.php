<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Our price/facts read share, counted on catalogue calls only; every call also consumes the full daily cap. */
final class CatalogueShare
{
    public const PERCENT=30;
    public const MARK='catalogue:';
    public static function limit(int $daily,int $percent=self::PERCENT): int
    { return intdiv($daily,100)*$percent+intdiv(($daily%100)*$percent,100); }
}
