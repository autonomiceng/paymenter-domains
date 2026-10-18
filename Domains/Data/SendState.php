<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum SendState: string
{
    case DefinitelyUnsent = 'definitely_unsent';
    case PossiblySent = 'possibly_sent';
    case Sent = 'sent';
}
