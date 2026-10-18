<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
enum TransferStatus: string
{
    case Unknown = 'unknown';
    case Pending = 'pending';
    case Completed = 'completed';
    case Rejected = 'rejected';
}
