<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementProgress;
/** Display candidacy for legacy envelopes whose held state alone cannot distinguish exhausted preparation. */
final class ManagementProgressIndex
{
    public static function capture(Operation $op): void
    {
        if (in_array($op->action,ManagementProgress::ACTIONS,true) && isset($op->getAttributes()['attempt'])) {
            if ($op->isDirty(['attempt', 'state', 'safe_code']) && !$op->managementAttempt()) {
                $op->management_pending = self::pending($op);
            }
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
