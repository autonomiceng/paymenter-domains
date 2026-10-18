<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum Action: string
{
    case Register = 'register';
    case Transfer = 'transfer';
    case Renew = 'renew';
}
