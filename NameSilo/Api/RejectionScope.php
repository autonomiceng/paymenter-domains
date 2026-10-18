<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\NameSilo\Api;

enum RejectionScope: string
{
    case Request = 'request';
    case Account = 'credential-account';
    case Funds = 'funds';
    case Domain = 'domain-state';
}
