<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
/** Connectivity only: no provider identity, contact or spending authority is returned. */
interface CredentialAuthenticator
{
    public function authenticate(Environment $environment, #[\SensitiveParameter] array $credentials): void;
}
