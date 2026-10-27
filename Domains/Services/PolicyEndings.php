<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Models\SalesPolicy;

/** Input-bounded current policy reads. Never a price or eligibility cache. */
final class PolicyEndings
{
    public static function parse(string $input): array
    {
        $name = CanonicalName::normalize($input);
        return CanonicalName::parse($name, SalesPolicy::whereIn('tld', self::candidates([$name]))->pluck('tld')->all());
    }

    /** Summary links retain their any-enabled-suffix rule, independently of admission parsing. */
    public static function transferEnabled(array $names): array
    {
        return SalesPolicy::whereIn('tld', self::candidates($names))->where('transfer_enabled', true)->pluck('tld')->all();
    }

    private static function candidates(array $names): array
    {
        $suffixes = [];
        foreach ($names as $name) {
            // Exclude the whole name: the existing match requires a preceding label and dot.
            while (($dot = strpos($name, '.')) !== false) {
                $name = substr($name, $dot + 1);
                $suffixes[$name] = true;
            }
        }
        return array_keys($suffixes);
    }
}
