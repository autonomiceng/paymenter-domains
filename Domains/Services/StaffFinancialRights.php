<?php
// Enom 444f31c staff financial rights; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
/** Current locked staff/role only. Permission registration and native routes remain35/39. */
final class StaffFinancialRights
{
    public static function require(array $l, string $reason): int
    {
        $staff = $l['staff'];
        if (!$staff instanceof User || !$staff->hasPermission('admin.domains.manage') || !$staff->hasPermission('admin.invoices.update')) {
            throw new \DomainException('Domain manage and invoice update permissions required.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{4,63}$/D', $reason)) { throw new \DomainException('A bounded financial reason code is required.'); }
        return (int) $staff->id;
    }
}
