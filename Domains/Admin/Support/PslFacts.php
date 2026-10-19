<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\PublicSuffixList;
final class PslFacts
{
    public static function result(?string $code): string
    {
        return match ($code) {
            null => 'No update attempted yet.', 'checking' => 'An update started. If it was interrupted, try again after the 24-hour limit.',
            'updated' => 'A validated list was saved.', 'identical' => 'The downloaded list is unchanged.', 'not_modified' => 'The source reports no change.',
            'rate_limited' => 'The 24-hour update limit has been reached.',
            'source_invalid' => 'The source URL must use HTTPS with no credentials, query or fragment.',
            'redirect_refused' => 'The source redirected the request. Redirects are refused.', 'timeout' => 'The update timed out.',
            'endpoint_unsafe' => 'The source did not resolve only to public addresses.',
            'too_small', 'too_large', 'too_few_rules' => 'The downloaded list was outside the accepted size or rule-count limits.',
            'markers_order', 'markers_missing' => 'The list sections were missing or in the wrong order.',
            'rule_invalid', 'invalid_utf8' => 'The downloaded list could not be parsed completely.',
            'sentinel_missing' => 'The downloaded list was missing required rules.',
            'rules_lost_icann' => 'More than 5% of the ICANN section’s previous rules were missing. The saved list was kept. See the README before changing anything.',
            'rules_lost_private' => 'More than 5% of the private section’s previous rules were missing. The saved list was kept. See the README before changing anything.',
            'unexpected_304' => 'The source reported no change without a matching saved list.',
            'internal_error' => 'The update failed internally. Review server logs.',
            'parser_unavailable' => 'The server’s international domain parser is unavailable.',
            'stored_copy_invalid' => 'The saved list could not be verified. See the README for recovery before updating.',
            default => 'The update could not be completed.',
        };
    }
    public static function time(?int $at): string { return $at === null ? 'Never' : gmdate('Y-m-d H:i:s', $at).' UTC'; }
    public static function state(PublicSuffixList $list): string
    {
        if ($list->revision() === null) { return 'Not downloaded yet'; }
        return !$list->discoveryAllowed() ? 'New discovery paused' : ($list->staleWarning() ? 'Out of date warning' : 'Current');
    }
    public static function rows(PublicSuffixList $list): array
    {
        $s = $list->current(); $status = $list->status();
        return ['Revision' => $s ? substr($s->revision, 0, 12) : 'None',
            'Last validated download' => self::time($s?->fetched_at),
            'Last successful source check' => self::time($status->checked_at), 'ICANN rules' => $s?->icann_count ?? 'None',
            'Private rules' => $s?->private_count ?? 'None', 'Download size' => $s ? number_format($s->byte_size).' bytes' : 'None',
            'Update source' => $list->source(), 'Saved list downloaded from' => $s?->source_url ?? 'None',
            'Last attempt' => self::time($status->last_attempt_at), 'Last attempt result' => self::result($status->last_result),
            'Consecutive unsuccessful attempts' => $status->consecutive_failures];
    }
}
