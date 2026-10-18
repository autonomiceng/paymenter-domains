<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum RenewalStatus: string
{
    case Unknown = 'unknown';
    case Ordinary = 'ordinary';
    case Grace = 'grace';
    case Redemption = 'redemption';
    case Ineligible = 'ineligible';
}
