<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Dns\RoutingProfile;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName, WatchProfile};

/** The option binds the nameservers staff previewed, including when the watch follows a moving default. */
final class WatchTemplateAssignment
{
    public static function choice(WatchProfile $profile, bool $inherit = false): string
    {
        return ($inherit ? 'site' : 'pin').':'.$profile->id.':'.$profile->revision;
    }

    public static function suggested(WatchName $watch, WatchProfile $profile, bool $inherit): ?string
    {
        if ($watch->expected_dns === 'external') { return 'external'; }
        if ($inherit) { return $watch->ns_profile_id === null ? ($watch->expected_dns ?? 'inherit') : 'inherit'; }
        if ((int) $watch->ns_profile_id === (int) $profile->id) { return $watch->expected_dns ?? 'unset'; }

        // The deployment's normalized host set is independent of the editable template name and site choice.
        $hosts = config('domains.site_dns_default.nameservers');
        if (!is_array($hosts) || !$hosts) { return null; }
        try { $deployment = new RoutingProfile($hosts, [], []); }
        catch (\InvalidArgumentException) { return null; }
        return $profile->readProfile()?->nameservers === $deployment->nameservers ? 'platform' : null;
    }

    /** Caller holds the settings and watch locks. A changed preview cannot be submitted. */
    public static function apply(WatchName $watch, string $choice, ?string $intent): void
    {
        if (!preg_match('/^(site|pin):([1-9][0-9]*):([1-9][0-9]*)$/D', $choice, $parts)) {
            throw new \DomainException('Choose a DNS template.');
        }
        $inherit = $parts[1] === 'site';
        $profile = $inherit ? SiteDnsDefault::profile(true) : WatchProfile::whereKey($parts[2])->lockForUpdate()->first();
        if (!ManagedDnsSetup::usable($profile) || self::choice($profile, $inherit) !== $choice) {
            throw new \DomainException('The template changed. Reload and check its nameservers.');
        }
        $watch->expected_dns = match (true) {
            in_array($intent, ['platform', 'external'], true) => $intent,
            $inherit && $intent === 'inherit' => null,
            !$inherit && $intent === 'unset' && (int) $watch->ns_profile_id === (int) $profile->id && $watch->expected_dns === null => null,
            default => throw new \DomainException('Choose what checks should expect.'),
        };
        $watch->ns_profile_id = $inherit ? null : $profile->id;
        $watch->profile_revision = $inherit ? 0 : $profile->revision;
    }
}
