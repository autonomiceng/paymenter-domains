<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum RequestOrigin: string
{
    case Interactive = 'interactive';
    case Background = 'background';
}
