<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. See ../LICENSE.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

enum Environment: string
{
    case Live = 'live';
    case Sandbox = 'sandbox';

    public function origin(): string
    {
        return match ($this) {
            self::Live => 'https://www.namesilo.com',
            self::Sandbox => 'https://sandbox.namesilo.com',
        };
    }
}
