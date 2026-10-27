<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;

use Paymenter\Extensions\Servers\Domains\Data\Environment;

interface AccountVerifier
{
    /** Read-only positive provider proof of the stable account owning these credentials.
     * No caller label/key hash may be returned as identity. Throw on unknown/rejection.
     * Implementations must bound reads and redact failures; only an explicitly composed verifier may perform I/O.
     */
    public function verify(Environment $environment, #[\SensitiveParameter] array $credentials): string;
}
