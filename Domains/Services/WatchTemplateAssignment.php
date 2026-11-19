<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Dns\RoutingProfile;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName, WatchProfile};

/** The option binds the nameservers staff previewed, including when the watch follows a moving default. */
final class WatchTemplateAssignment
{
    public static function choice(WatchProfile $profile, bool $inherit = false, bool $verified = false): string
    {
        return ($inherit ? 'site' : ($verified ? 'deployment' : 'pin')).':'.$profile->id.':'.$profile->revision;
    }

    public static function suggested(WatchName $watch, WatchProfile $profile, bool $inherit, bool $verified): ?string
    {
        if ($watch->expected_dns === 'external') { return 'external'; }
        if ($inherit) { return $watch->ns_profile_id === null ? ($watch->expected_dns ?? 'inherit') : 'inherit'; }
        if ((int) $watch->ns_profile_id === (int) $profile->id) { return $watch->expected_dns ?? 'unset'; }

        return $verified ? 'platform' : null;
    }

    public static function verified(WatchProfile $profile, ?WatchProfile $siteDefault): bool
    {
        // The deployment's normalized host set is independent of the editable template name and site choice.
        if ($siteDefault?->id !== $profile->id) { return false; }
        $hosts = config('domains.site_dns_default.nameservers');
        if (!is_array($hosts) || !$hosts) { return false; }
        try { $deployment = new RoutingProfile($hosts, [], []); }
        catch (\InvalidArgumentException|\TypeError) { return false; }
        return $profile->readProfile()?->nameservers === $deployment->nameservers;
    }

    /** Caller holds the settings and watch locks. A changed preview cannot be submitted. */
    public static function apply(WatchName $watch, string $choice, ?string $intent): void
    {
        if (!preg_match('/^(site|pin|deployment):([1-9][0-9]*):([1-9][0-9]*)$/D', $choice, $parts)) {
            throw new \DomainException('Choose a DNS template.');
        }
        $inherit = $parts[1] === 'site';
        $profile = $inherit ? SiteDnsDefault::profile(true) : WatchProfile::whereKey($parts[2])->lockForUpdate()->first();
        $verified = $parts[1] === 'deployment';
        if (!ManagedDnsSetup::usable($profile) || self::choice($profile, $inherit, $verified) !== $choice
            || ($verified && !self::verified($profile, SiteDnsDefault::profile(true)))) {
            throw new \DomainException('The template changed. Reload and check its nameservers.');
        }
        $watch->expected_dns = match (true) {
            in_array($intent, ['platform', 'external'], true) => $intent,
            $inherit && $intent === 'inherit' => null,
            !$inherit && $intent === 'unset' && (int) $watch->ns_profile_id === (int) $profile->id && $watch->expected_dns === null => null,
            default => throw new \DomainException('Choose what checks should expect.'),
        };
        $watch->ns_profile_id = $inherit ? null : $profile->id;
        // Checks already follow the live template revision. Reconfirming the same assignment must keep their history.
        if ($watch->isDirty(['ns_profile_id', 'expected_dns'])) {
            $watch->profile_revision = $inherit ? 0 : $profile->revision;
        }
    }
}
