<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;

/** Read-only pre-deployment check. It never converts or activates a retained installation. */
final class OfferUpgrade
{
    public const BRIDGE = '89112442164558c4ab61ff32f210d1b102605112';
    public const MESSAGE = 'Install Domains bridge 0.1.0 at 891124421645 first, run the checked catalogue conversion, then retry.';

    public static function requireCompatible(): void
    {
        try {
            $state = UpgradeGate::initializationState();
            if (in_array($state['state'], ['active', 'initializing'], true)) { return; }
            if ($state['state'] === 'legacy' && $state['generation'] === 0 && FreshInstallation::uninstalled()) { return; }
        } catch (\DomainException) { /* A damaged receipt cannot authorize replacement or migration. */ }
        throw new \DomainException(self::MESSAGE);
    }
}
