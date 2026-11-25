<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Installation\{Installer,Registry,Verification};

/** Stable entry point for Landing, maintenance scripts and an older package's lifecycle; the Installation module owns the behaviour. */
final class Installation
{
    public const BASELINE = Registry::BASELINE;
    public const SCHEMAS = Registry::SCHEMAS;
    public const VERIFIED_KEY = Verification::KEY;

    public static function legacy(): void { Installer::legacy(); }
    public static function tables(): array { return Registry::tables(); }
    public static function expectedTables(): array { return Registry::expectedTables(); }
    public function install(): void { Installer::install(); }
    public function verify(): void { Verification::run(); }
    public function verifyForOrders(): void { Verification::forOrders(); }
}
