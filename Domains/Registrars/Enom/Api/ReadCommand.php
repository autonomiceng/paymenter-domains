<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

/** Closed read-only protocol: no purchase or management mutations can be dispatched. */
enum ReadCommand: string
{
    case Balance = 'GetBalance';
    case Tlds = 'GetTLDList';
    case ProductPrice = 'PE_GetProductPrice';
    case TldInfo = 'GetTLDDetails';
    case Attributes = 'GetExtAttributes';
    case Check = 'Check';
    case Info = 'GetDomainInfo';
    case Expiry = 'GetDomainExp';
    case RenewalInfo = 'GetExtendInfo';
    case Contacts = 'GetContacts';
    case Lock = 'GetRegLock';
    case Privacy = 'GetWPPSInfo';
    case Renewal = 'GetRenew';

    public function bodyLimit(): int { return $this === self::Tlds ? 8388608 : 1048576; }
}
