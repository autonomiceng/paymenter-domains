<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic PHP extension availability only.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
function extension_loaded(string $name): bool
{
    return $name==='curl' && ($GLOBALS['domainsTestCurlMissing']??false) ? false : \extension_loaded($name);
}
