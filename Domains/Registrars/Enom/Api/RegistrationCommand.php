<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;
/** Parsing vocabulary only; read clients/transports still accept ReadCommand exclusively. */
enum RegistrationCommand: string
{
    case Purchase = 'Purchase';
    case Nameservers = 'ModifyNS';
    case NameserverReadback = 'GetDNS';
    public function bodyLimit(): int { return 1048576; }
}
