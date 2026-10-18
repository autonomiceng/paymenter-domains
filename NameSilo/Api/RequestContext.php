<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\NameSilo\Api;

enum RequestContext: string
{
    case Interactive = 'api';
    case Background = 'apibatch';
}
