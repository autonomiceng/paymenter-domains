<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum ResultReason: string
{
    case Acknowledged = 'acknowledged';
    case Fallback = 'fallback';
    case Processing = 'processing';
    case ProvenRejected = 'proven_rejected';
    case Unsent = 'unsent';
    case Unresolved = 'unresolved';
    case Unsupported = 'unsupported';
    case BindingMismatch = 'binding_mismatch';
    case Unverified = 'unverified';
}
