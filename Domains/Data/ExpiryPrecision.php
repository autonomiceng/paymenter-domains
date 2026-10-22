<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum ExpiryPrecision: string
{
    case Date = 'date';
    case Instant = 'instant';
    case Local = 'local'; // Wall-clock seconds with no known UTC offset.
}
