<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum Environment: string
{
    case Live = 'live';
    case Test = 'test';
}
