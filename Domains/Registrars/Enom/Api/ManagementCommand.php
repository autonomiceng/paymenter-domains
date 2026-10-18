<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;
/** Parser vocabulary only. Read transport still refuses management mutations. */
enum ManagementCommand: string
{
    case Nameservers = 'ModifyNS'; case Contacts = 'Contacts'; case Lock = 'SetRegLock';
    case Email = 'SynchAuthInfo'; case AutoRenew = 'SetRenew'; case Enable = 'EnableServices'; case Disable = 'DisableServices';
    case Purchase = 'PurchaseServices'; case Renew = 'RenewServices';
    case Dns = 'SetHosts'; case Srv = 'SetDomainSRVHosts'; case DsAdd = 'AddDnsSec'; case DsDelete = 'DeleteDnsSec';
    case GlueAdd = 'RegisterNameServer'; case GlueUpdate = 'UpdateNameServer'; case GlueDelete = 'DeleteNameServer';
    public function bodyLimit(): int { return 1048576; }
}
