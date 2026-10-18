<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum PrivacyMode: string
{
    case Unknown = 'unknown';
    case Unavailable = 'unavailable';
    case Free = 'free';
    case Paid = 'paid';
}
