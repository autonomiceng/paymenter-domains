<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

enum ManagementEvidenceKind: string
{
    case ReserveRead = 'management_read_reserved';
    case Readback = 'management_readback';
    case Deferral = 'management_deferral';
    case Retained = 'management_retained';
}
