<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

enum AcquisitionClosureKind: string
{
    case Unsent = 'definitely_unsent';
    case Unpaid = 'unpaid_closed';
    case TransferTimeout = 'transfer_code_timeout';
}
