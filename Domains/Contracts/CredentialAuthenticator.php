<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
/** Connectivity only: no provider identity, contact or spending authority is returned. */
interface CredentialAuthenticator
{
    /** No usable reply, or a reply that neither confirms nor refuses the key. */
    public const UNCONFIRMED = 'Registrar key could not be confirmed.';
    /** The registrar answered and refused the key or its account access. */
    public const REFUSED = 'Registrar refused the key.';
    /** The registrar did not answer at all. */
    public const UNANSWERED = 'Registrar did not answer.';
    public function authenticate(Environment $environment, #[\SensitiveParameter] array $credentials): void;
}
