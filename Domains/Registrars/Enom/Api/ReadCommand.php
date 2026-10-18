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
    case Expired = 'GetExpiredDomains';
    case Order = 'GetOrderDetail';
    case Transfer = 'TP_GetOrder';
    case Orders = 'GetOrderList';
    case Nameservers = 'GetDNS'; case Hosts = 'GetHosts'; case Srv = 'GetDomainSRVHosts';
    case Ds = 'GetDnsSec'; case HostStatus = 'CheckNSStatus';

    /** Exact parameters for the named read; no shared credential/mutation overrides. */
    public function validate(#[\SensitiveParameter] array $parameters): void
    {
        $expected = match ($this) {
            self::Balance, self::Tlds, self::Expired => [],
            self::Order => ['OrderID'],
            self::HostStatus => ['CheckNSName'],
            self::Transfer => ['TransferOrderID'],
            self::Orders => ['BeginDate', 'EndDate', 'Start'],
            self::ProductPrice => ['TLD', 'ProductType', 'Years'],
            self::TldInfo, self::Attributes => ['TLD'],
            self::Check => ['SLD', 'TLD', 'Version', 'IncludePrice', 'IncludeProperties', 'IncludeEAP'],
            default => ['SLD', 'TLD'],
        };
        if (array_diff(array_keys($parameters), $expected) || array_diff($expected, array_keys($parameters))) {
            throw new \InvalidArgumentException('Invalid named read parameters.');
        }
        foreach ($parameters as $key => $value) {
            $valid = match ($key) {
                'CheckNSName' => is_string($value) && strlen($value) <= 253 && (bool) filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME),
                'SLD' => is_string($value) && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value),
                'TLD' => is_string($value) && strlen($value) <= 189 && preg_match('/^[a-z]{2,63}(?:\.[a-z]{2,63})*$/D', $value),
                'OrderID', 'TransferOrderID' => is_string($value) && preg_match('/^[1-9][0-9]{0,19}$/D', $value),
                'BeginDate', 'EndDate' => is_string($value) && preg_match('/^\d{2}\/\d{2}\/\d{4}$/D', $value),
                'Start' => $value === 1,
                'ProductType' => in_array($value, [10, 16, 19], true),
                'Years' => is_int($value) && $value >= 1 && $value <= 10,
                'Version' => $value === 2,
                default => $value === 1,
            };
            if (!$valid) { throw new \InvalidArgumentException('Invalid named read value.'); }
        }
    }
    public function bodyLimit(): int { return $this === self::Tlds ? 8388608 : 1048576; }
}
