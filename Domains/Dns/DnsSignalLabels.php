<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Semantic keys for a future escaped presenter. Unknown/stale never produces empty-points copy. */
final class DnsSignalLabels
{
    public static function keys(?string $dns, ?bool $website, ?bool $email, bool $mixed = false, bool $partial = false): array
    {
        if (!in_array($dns, [null, 'platform', 'external'], true) || ($mixed && $website !== true && $email !== true)) { throw new \InvalidArgumentException('Invalid delegation axis.'); }
        $keys = [$dns === null ? 'domain.dns.unknown' : 'domain.dns.'.$dns];
        if ($website === true && $email === true) { $keys[] = 'domain.points.both'; }
        elseif ($website === true) { $keys[] = 'domain.points.website'; }
        elseif ($email === true) { $keys[] = 'domain.points.email'; }
        $positive = $website === true || $email === true;
        if ($mixed) { $keys[] = 'domain.points.mixed'; }
        if ($positive) {
            if ($partial || $website === null || $email === null) { $keys[] = 'domain.points.partial'; }
        } elseif ($partial || $dns === null || $website === null || $email === null) { $keys[] = 'domain.points.unknown'; }
        else { $keys[] = $dns === 'platform' ? 'domain.points.platform_empty' : 'domain.points.external_empty'; }
        return $keys;
    }
    public static function projection(array $signals): array
    {
        $partial = ($signals['partial'] ?? true) !== false;
        foreach (['dns','website','email'] as $axis) {
            $partial = $partial || ($signals[$axis]['available'] ?? false) !== true || ($signals[$axis]['stale'] ?? true) !== false;
        }
        $website = $signals['website']['value'] ?? null; $email = $signals['email']['value'] ?? null;
        // Independent last-good rows can retain a mixed flag whose positive axis was superseded.
        $mixed = ($website === true || $email === true) && (($signals['website']['mixed'] ?? false) || ($signals['email']['mixed'] ?? false));
        return self::keys($signals['dns']['value'] ?? null, $website, $email, $mixed, $partial);
    }
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
    public static function transferTarget(): string { return self::company() ?: 'us'; }
    public static function configuredStrings(): array { return self::strings(config('settings.company_name')); }
    public static function strings(mixed $companyName): array
    {
        $company = self::company($companyName);
        return ['domain.dns.platform' => ($company !== '' ? $company.' DNS' : 'Our DNS'), 'domain.dns.external' => 'External DNS', 'domain.dns.unknown' => 'DNS details unavailable',
            'domain.points.website' => 'Points to '.($company ?: 'us').': website', 'domain.points.email' => 'Points to '.($company ?: 'us').': email',
            'domain.points.both' => 'Points to '.($company ?: 'us').': website / email', 'domain.points.mixed' => 'Also points to other services',
            'domain.points.partial' => 'Some checks are unavailable', 'domain.points.help' => 'These checks show DNS pointers. They do not test website or email availability.',
            'domain.points.unknown' => 'Connection details unavailable', 'domain.points.platform_empty' => 'DNS only', 'domain.points.external_empty' => 'Not pointed to '.($company ?: 'us')];
    }
}
