<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
final class WriteHeld extends \DomainException
{
    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, ['write_disabled', 'write_acceptance_required', 'write_rate_limited', 'contact_profile_proof_required'], true)) {
            throw new \InvalidArgumentException('Invalid write hold.');
        }
        parent::__construct('Registrar write is held.');
    }
}
