<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
/** Connectivity only: no provider identity, contact or spending authority is returned. */
interface CredentialAuthenticator
{
    /** No reply came back (HTTP failure, deadline or no JSON): the key was not refused. */
    public const UNCONFIRMED = 'Registrar key could not be confirmed.';
    public const UNANSWERED = 'Registrar did not answer.';
    public function authenticate(Environment $environment, #[\SensitiveParameter] array $credentials): void;
}
