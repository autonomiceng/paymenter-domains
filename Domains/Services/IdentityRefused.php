<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Closed diagnostics only; never attach a provider exception or response. */
final class IdentityRefused extends \DomainException
{
    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, ['identity_credentials_refused', 'identity_unavailable', 'identity_environment_refused',
            'identity_account_changed', 'namesilo_identity_unavailable'], true)) { throw new \LogicException('Invalid identity refusal.'); }
        parent::__construct($reason);
    }
}
