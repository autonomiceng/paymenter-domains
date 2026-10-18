<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum Disposition: string
{
    case Accepted = 'accepted';
    case AcceptedWithDiscrepancy = 'accepted_with_discrepancy';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
