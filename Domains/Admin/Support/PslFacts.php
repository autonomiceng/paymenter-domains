<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\{PublicSuffixList,PslFailure};
final class PslFacts
{
    public static function result(?string $code): string { return PslActions::result($code); }
    public static function time(?int $at): string { return $at === null ? 'Never' : gmdate('Y-m-d H:i:s', $at).' UTC'; }
    public static function state(PublicSuffixList $list): string
    {
        if ($list->revision() === null) { return 'Not downloaded yet'; }
        try { PublicSuffixList::body($list->current()); } catch (PslFailure) { return 'Saved list damaged'; }
        if (!$list->parserUsable()) { return 'Saved list needs review'; }
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
