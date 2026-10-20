<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile,WatchDnsObservation};
/** Owner-scoped read only. Each failed axis keeps its prior value/time explicitly stale. */
final class WatchDnsProjection
{
    public function read(int $watchId, int $userId, int $now): ?array
    {
        $watch = WatchName::whereKey($watchId)->where('user_id', $userId)->first();
        if ($watch === null) { return null; }
        $profile = WatchProfile::find($watch->ns_profile_id); $out = [];
        foreach (['dns' => 'delegation', 'website' => 'points', 'email' => 'points'] as $axis => $kind) {
            $base = WatchDnsObservation::where('watch_id', $watchId)->where('user_id', $userId)->where('check_kind', $kind);
            $work = DB::table('domains_watch_work')->where('watch_id',$watchId)->where('kind',$kind)->first();
            $failed = $work === null || ($work->safe_code !== null && !in_array($work->safe_code, ['budget_exhausted','rotation'], true));
            $latest = (clone $base)->orderByDesc('id')->first();
            $good = (clone $base)->whereNotNull($axis)->orderByDesc('id')->first();
            $stamp = (new WatchDnsChecks())->currentStamp($watchId, $userId, (int) ($good?->source_cursor ?? 0));
            $stale = $failed || $good === null || $stamp === null || ($good !== null && !hash_equals($good->source_digest, $stamp)) || $latest?->{$axis} === null || $good->partial || $now < $good->started_at || $now >= $good->valid_until ||
                $watch->state !== 'active' || !$profile?->enabled || (int) $good->profile_id !== (int) $profile?->id ||
                (int) $good->profile_revision !== (int) $profile?->revision || (int) $good->watch_revision !== (int) $watch->revision || $good->psl_revision !== $watch->psl_revision;
            $out[$axis] = ['value' => $good?->{$axis}, 'checked_at' => $good?->started_at, 'valid_until' => $good?->valid_until,
                'stale' => $stale, 'notice_eligible'=>!$stale && ($kind!=='delegation' || ($good?->facts['ns_confirmed']??false)), 'available' => $latest !== null && $latest->{$axis} !== null, 'mixed' => $good?->mixed ?? false];
        }
        $out['partial'] = !$out['dns']['available'] || !$out['website']['available'] || !$out['email']['available'] ||
            $out['dns']['stale'] || $out['website']['stale'] || $out['email']['stale'];
        return $out;
    }
}
