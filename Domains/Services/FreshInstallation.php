<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Installation\Installer;

/** Stable entry point: Landing's archive rebuild check loads this file from a candidate ZIP and calls requireCompatible(). */
final class FreshInstallation
{
    public const CONTRACT = Installer::CONTRACT;
    public const MESSAGE = Installer::MESSAGE;
    public const STAFF = Installer::STAFF;

    public static function requireCompatible(): void { Installer::requireCompatible(); }
}
