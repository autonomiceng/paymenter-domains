<?php
// Vendor vocabulary facts only; no code copied. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\TransferStatus;
/** Exact latest-status vocabulary; completion still requires independent financial/readback proof. */
final class TransferState
{
    public static function read(mixed $status): TransferStatus
    {
        return match ($status) {
            'Retrieving Administrative Contact Email', 'Retrieving Administrative Contact Email (2)',
            'Pending Reply from Administrative Contact', 'Transfer Accepted', 'Approved', 'Approved (Auto)',
            'Checking Domain Status', 'Submitting Transfer Request to Registry', 'Pending at Registry' => TransferStatus::Pending,
            'Transfer Completed' => TransferStatus::Completed,
            // Wait Authorization Code still requires a code; Domain is Private requires disabling privacy/WHOIS masking at the current registrar.
            'Missing Authorization Code', 'Incorrect Authorization Code', 'Domain is Locked',
            'Wait Authorization Code', 'Domain is Private' => TransferStatus::NeedsAction,
            'Transfer Rejected', 'Registry Transfer Request Failed', 'Registrar Rejected',
            'On Hold - Created in last 60 days', 'On Hold - Transferred in last 60 days',
            'Registry Rejected', 'Domain Transferred Elsewhere', 'User Cancelled',
            'Domain has a pendingDelete status', 'Domain has a pendingTransfer status' => TransferStatus::Rejected,
            // Known but deliberately unknown: finality and charge outcome remain unconfirmed.
            'Transfer Timed Out', 'Time Out', 'Transfer price changed' => TransferStatus::Unknown,
            default => TransferStatus::Unknown,
        };
    }
}
