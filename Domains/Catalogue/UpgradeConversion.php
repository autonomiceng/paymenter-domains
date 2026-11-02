<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;

/** Explicit, dormant upgrade entry point. B6 owns the quiet window and caller switch. */
final class UpgradeConversion
{
    public function batch(AccountBinding $binding, int $limit = 100): array
    {
        return ['converted' => 0, 'complete' => false];
    }
}
