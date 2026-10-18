<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

final readonly class AccountBinding
{
    public string $fingerprint;

    /** Stable identity is not a credential/key hash; construction does not prove account ownership. */
    public function __construct(public string $registrarKey, public int $serverId, public Environment $environment,
        #[\SensitiveParameter] string $stableIdentity)
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $registrarKey) || $serverId < 1
            || $stableIdentity === '' || strlen($stableIdentity) > 255 || preg_match('/[\x00-\x1f\x7f]/', $stableIdentity)) {
            throw new \InvalidArgumentException('Invalid registrar account binding.');
        }
        $this->fingerprint = hash('sha256', json_encode([$registrarKey, $serverId, $environment->value, $stableIdentity], JSON_THROW_ON_ERROR));
    }

    public function matches(self $other): bool { return hash_equals($this->fingerprint, $other->fingerprint); }
    public function requireMatch(self $other): void
    {
        if (!$this->matches($other)) { throw new \InvalidArgumentException('Registrar account binding mismatch.'); }
    }
}
