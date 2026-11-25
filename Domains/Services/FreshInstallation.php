<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Installation\Installer;

/**
 * Stable entry point. Landing's archive rebuild check loads this file from a candidate ZIP, and an upload over an
 * older package runs that package's Domains class, which calls these members after the new files are in place.
 */
final class FreshInstallation
{
    public const CONTRACT = Installer::CONTRACT;
    public const MESSAGE = Installer::MESSAGE;
    public const STAFF = Installer::STAFF;

    public static function requireCompatible(): void { Installer::requireCompatible(); }
    public static function install(): void { Installer::installFresh(); }
    public static function uninstalled(): bool { return Installer::uninstalled(); }
    public static function recovering(): bool { return Installer::recovering(); }
}
