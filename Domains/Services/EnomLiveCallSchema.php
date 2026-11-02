<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Retained installation and reader entry points for the shared Live ledger. */
final class EnomLiveCallSchema
{
    public const TABLE=LiveReservationSchema::TABLE;
    public const MIGRATION=LiveReservationSchema::MIGRATION;
    public static function install(): void {LiveReservationSchema::install();}
    public static function verify(): void {LiveReservationSchema::verify();}
}
