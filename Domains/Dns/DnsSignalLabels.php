<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Company and nameserver labels shared by customer and staff pages. */
final class DnsSignalLabels
{
    /** Use the configured company, then the app name, without a trailing environment suffix. */
    public static function company(mixed $setting = null): string
    {
        $trim = static fn (mixed $value): string => is_string($value) ? trim((string) preg_replace('/\s*\((?:local|dev|development|test|testing|stage|staging|demo|sandbox|preview)\)\s*\z/i', '', trim($value))) : '';
        $name = $trim($setting ?? (function_exists('config') ? config('settings.company_name') : null));
        return $name !== '' ? $name : $trim(function_exists('config') ? config('app.name') : null);
    }
    public static function nameservers(): string
    {
        $company = self::company();
        return $company !== '' ? $company.' nameservers' : 'our nameservers';
    }
}
