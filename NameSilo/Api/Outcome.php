<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\NameSilo\Api;

enum Outcome: string
{
    case Success = 'success';
    case Fallback = 'success-with-fallback';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
