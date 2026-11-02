<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;

interface Command
{
    public function binding(): AccountBinding;
    public function tld(): string;
}
