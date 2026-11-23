<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Derived display candidacy only; execution still requires the original encrypted evidence. */
final class ManagementProgressIndex
{
    public static function capture(Operation $op): void
    {
        if (in_array($op->action,ManagementProgress::ACTIONS,true)) {
            $op->management_pending=self::pending($op);
        }
    }
    private static function pending(Operation $op): bool
    {
        try {
            if (!isset($op->policy['management'])) { return false; }
            $d=Domain::find($op->domain_id);
            return !$d || ManagementProgress::pending($op,$d);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) { return true; }
    }
}
