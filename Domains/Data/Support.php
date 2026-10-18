<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum Support: string
{
    case Unknown = 'unknown';
    case Unsupported = 'unsupported';
    case Supported = 'supported';
}
