<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

/** Adoption v1 is deliberately narrower than the general read protocol. */
enum AdoptionReadCommand: string
{
    case Info = 'GetDomainInfo';
    case Expiry = 'GetDomainExp';
    case Renewal = 'GetRenew';
    case RenewalInfo = 'GetExtendInfo';

    public function protocol(): ReadCommand { return ReadCommand::from($this->value); }
}
