<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

enum AcquisitionChargeMatch: string
{
    case Exact = 'exact';
    case Ambiguous = 'ambiguous';
}
