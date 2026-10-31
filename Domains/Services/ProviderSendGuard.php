<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/**
 * Optional policy immediately before a physical registrar send. CONTRACT 1 callback arguments:
 * registrar: 'enom' or 'namesilo'; environment: 'test' or 'live' (NameSilo OTE is 'test').
 * Enom commands: GetBalance, GetTLDList, PE_GetProductPrice, PE_GetRetailPricing, PE_GetDomainPricing, GetTLDDetails, GetExtAttributes,
 * Check, GetDomainInfo, GetDomainExp, GetExtendInfo, GetContacts, GetRegLock, GetWPPSInfo,
 * GetRenew, GetExpiredDomains, GetOrderDetail, TP_GetOrder, GetOrderList, GetDNS, GetHosts,
 * GetDomainSRVHosts, GetDnsSec, CheckNSStatus, Purchase, TP_CreateOrder, Extend,
 * UpdateExpiredDomains, ModifyNS, Contacts, SetRegLock, SynchAuthInfo, SetRenew,
 * EnableServices, DisableServices, PurchaseServices, RenewServices, SetHosts,
 * SetDomainSRVHosts, AddDnsSec, DeleteDnsSec, RegisterNameServer, UpdateNameServer, DeleteNameServer.
 * NameSilo API commands: getAccountBalance, getPrices, checkRegisterAvailability,
 * checkTransferAvailability, getDomainInfo, checkTransferStatus, listOrders, orderDetails, contactList.
 * Fourth argument: extension Clock::now() converted to UTC. No payload or credentials are passed.
 */
final class ProviderSendGuard
{
    public const CONTRACT = 1;

    public static function beforeSend(string $registrar,string $environment,string $command): void
    {
        $reason=null;
        try {
            $guard=function_exists('config')?config('domains.provider_send_guard'):null;
            if($guard===null){return;}
            if(!is_callable($guard)){$reason='not_callable';throw new \RuntimeException;}
            $reason='clock_failed';
            $now=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'));
            $reason='guard_threw';
            $guard($registrar,$environment,$command,$now);
        } catch(\Throwable) {
            if($reason!==null){
                try { logger()->warning('Domains provider send guard refused.',['registrar'=>$registrar,'command'=>$command,'reason'=>$reason]); }
                catch(\Throwable) {}
            }
            // Preserve the existing definitely-unsent path without exposing deployment exceptions.
            if($registrar==='enom'){EnomTestCalls::refuse($reason==='guard_threw'?'send_guard':'send_check');}
            throw new \RuntimeException('Registrar request refused before sending.');
        }
    }
}
