<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\IdentityRefused;
/** Future provider-confirmed identity seam. No reviewed ownership proof exists today. */
final class AccountVerifier implements \Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier
{
    public function verify(Environment $environment,#[\SensitiveParameter] array $credentials): string
    {throw new IdentityRefused('namesilo_identity_unavailable');}
}
