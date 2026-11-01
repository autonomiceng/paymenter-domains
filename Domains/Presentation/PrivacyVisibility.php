<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Display only: stored privacy data and registrar behavior remain independent of these views. */
final class PrivacyVisibility
{
    public const SHOWN=false;
    public static function registrarNotice(): string
    {
        $company=\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company();
        return 'Not offered. Enom charges for it and '.($company!==''?$company." doesn't":"we don't").' sell it yet.';
    }
}
