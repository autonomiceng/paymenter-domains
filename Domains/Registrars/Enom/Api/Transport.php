<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

use Paymenter\Extensions\Servers\Domains\Data\Environment;

interface Transport
{
    /** Return a complete HTTP-200 body or throw; no retry, redirect or partial response. */
    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string;
}
