<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Hide new privacy choices without changing stored data or dispatching existing orders. */
final class PrivacyVisibility
{
    public const SHOWN=false;
    public static function registrarNotice(?string $registrar): string
    {
        if ($registrar==='namesilo') { return 'Not offered here yet. NameSilo includes it free where eligible.'; }
        if ($registrar!=='enom') { return 'Not offered yet.'; }
        $company=\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company();
        return 'Not offered. Enom charges for it and '.($company!==''?$company." doesn't":"we don't").' sell it yet.';
    }
}
