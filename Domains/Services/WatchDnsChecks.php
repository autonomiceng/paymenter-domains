<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Order,Product,Service,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsQuery,DnsReading,WatchDnsTicket};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile,WatchSource,WatchProductMap,WatchDnsObservation};
/** Dormant local work boundary. No dispatcher, container binding or public query entry point. */
final class WatchDnsChecks
{
    public function __construct(private bool $enabled = false) {}
    public function enroll(int $watchId, int $userId, int $now): bool
    {
        if (!$this->enabled || $now < 0 || DB::transactionLevel() !== 0) { return false; }
        return DB::transaction(function () use ($watchId, $userId, $now): bool {
            $target = $this->target($watchId, $userId);
            if ($target === null) { return false; }
            foreach (['delegation', 'points'] as $kind) {
                $q = DB::table('domains_watch_work')->where('watch_id', $watchId)->where('kind', $kind);
                if (!$q->lockForUpdate()->exists()) {
                    DB::table('domains_watch_work')->insert(['watch_id' => $watchId, 'kind' => $kind, 'due_at' => $now,
                        'watch_revision' => $target['watch']->revision, 'attempts' => 0, 'cursor' => 0]);
                }
            }
            return true;
        });
    }
    public function begin(int $watchId, int $userId, string $kind, int $now): ?WatchDnsTicket
    {
        if (!$this->enabled || $now < 0 || !in_array($kind, ['delegation', 'points'], true) || DB::transactionLevel() !== 0) { return null; }
        return DB::transaction(function () use ($watchId, $userId, $kind, $now): ?WatchDnsTicket {
            $peek = DB::table('domains_watch_work')->where('watch_id',$watchId)->where('kind',$kind)->first();
            if ($peek === null) { return null; }
            $t = $this->target($watchId, $userId, true, (int) $peek->source_cursor);
            if ($t === null) { return null; }
            $work = DB::table('domains_watch_work')->where('watch_id', $watchId)->where('kind', $kind)->lockForUpdate()->first();
            if ($work === null || (int) $work->source_cursor !== (int) $peek->source_cursor || $work->due_at > $now || ($work->backoff_until ?? 0) > $now || ($work->lease_until ?? 0) > $now) { return null; }
            $claim = bin2hex(random_bytes(32));
            DB::table('domains_watch_work')->where('id', $work->id)->update(['claim' => $claim, 'lease_until' => $now + 120, 'watch_revision' => $t['watch']->revision]);
            $watch = $t['watch']; $profile = $t['profile'];
            $dnsOnly = WatchDnsObservation::where('watch_id', $watchId)->where('check_kind', 'points')->where('result', 'complete')
                ->where('profile_id', $profile->id)->where('profile_revision', $profile->revision)->where('source_digest', $t['stamp'])
                ->where('valid_until', '>', $now)->where('started_at', '<=', $now)->orderByDesc('id')->first();
            $interval = $kind === 'points' ? 86400 : ($watch->expected_dns === 'platform' && $dnsOnly?->website === false && $dnsOnly?->email === false ? 3600 : 21600);
            return new WatchDnsTicket($watchId, $userId, $kind, $claim, $now, $t['stamp'], (int) $profile->id, $profile->revision,
                $watch->psl_revision, $watch->revision, $profile->profile(), $t['hosts'], (int) $work->cursor, $interval, (int) $work->source_cursor, $t['next_source_cursor'], $t['overflow']);
        });
    }
    public function finish(WatchDnsTicket $ticket, DnsReading $reading, int $now, int $nextCursor = 0, ?int $nextSourceCursor = null): bool
    {
        if (!$this->enabled || DB::transactionLevel() !== 0 || $reading->kind !== $ticket->kind || $nextCursor < 0 || ($nextSourceCursor !== null && !in_array($nextSourceCursor, [$ticket->sourceCursor,$ticket->nextSourceCursor], true))) { return false; }
        return DB::transaction(function () use ($ticket, $reading, $now, $nextCursor, $nextSourceCursor): bool {
            $t = $this->target($ticket->watchId, $ticket->userId, true, $ticket->sourceCursor);
            $q = DB::table('domains_watch_work')->where('watch_id', $ticket->watchId)->where('kind', $ticket->kind);
            $work = $q->lockForUpdate()->first();
            if ($work === null || $work->claim !== $ticket->claim || (int) $work->source_cursor !== $ticket->sourceCursor) { return false; }
            if ($t === null || !hash_equals($ticket->stamp, $t['stamp'])) {
                $q->update(['claim' => null, 'lease_until' => null, 'due_at' => max($ticket->startedAt, $now) + 60, 'safe_code' => 'target_changed']);
                return false;
            }
            if ($now < $ticket->startedAt || $now >= $ticket->startedAt + 45 || $now >= (int) $work->lease_until) {
                // Reject late values; retain a failed-work marker so old evidence is visibly stale.
                $attempts = min(30, (int) $work->attempts + 1); $delay = [900,3600,21600,86400][min(3,$attempts-1)];
                $jitter = (int) (hexdec(substr(hash('sha256',$ticket->watchId.'|'.$ticket->kind.'|'.$attempts),0,6)) % (intdiv($delay,10)+1));
                $due = max($ticket->startedAt, $now) + $delay + $jitter;
                $q->update(['claim'=>null,'lease_until'=>null,'attempts'=>$attempts,'due_at'=>$due,'backoff_until'=>$due,
                    'safe_code'=>$now < $ticket->startedAt ? 'clock_reversal' : 'deadline']);
                return false;
            }
            if ($ticket->sourceOverflow) { $reading = $reading->withOmittedHosts(); }
            $result = $reading->result(); $complete = $result === 'complete'; $facts = $reading->facts();
            $code = $reading->safeCode(); $rotation = $code === 'rotation'; $budget = $rotation || $code === 'budget_exhausted';
            $valid = $result === 'unknown' ? null : $ticket->startedAt + 2 * $ticket->interval;
            $row = WatchDnsObservation::create(['watch_id' => $ticket->watchId, 'user_id' => $ticket->userId,
                'watch_revision' => $ticket->watchRevision, 'profile_id' => $ticket->profileId, 'profile_revision' => $ticket->profileRevision,
                'psl_revision' => $ticket->pslRevision, 'source_digest' => $ticket->stamp, 'source_cursor' => $ticket->sourceCursor, 'check_kind' => $ticket->kind,
                'started_at' => $ticket->startedAt, 'completed_at' => $now, 'valid_until' => $valid, 'result' => $result,
                'dns' => $reading->dns, 'website' => $reading->website, 'email' => $reading->email, 'mixed' => $reading->mixed,
                'partial' => $result !== 'complete', 'safe_code' => $code, 'facts' => $facts,
                'digest' => $reading->digest()]);
            $attempts = $complete ? 0 : ($budget ? (int) $work->attempts : min(30, (int) $work->attempts + 1));
            $delay = ($complete || $rotation) ? $ticket->interval : ($budget ? 60 : [900, 3600, 21600, 86400][min(3, $attempts - 1)]);
            $jitter = ($complete || $budget) ? 0 : (int) (hexdec(substr(hash('sha256', $ticket->watchId.'|'.$ticket->kind.'|'.$attempts), 0, 6)) % (intdiv($delay, 10) + 1));
            $q->update(['claim' => null, 'lease_until' => null, 'attempts' => $attempts, 'due_at' => $now + $delay + $jitter,
                'backoff_until' => ($complete || $budget) ? null : $now + $delay + $jitter, 'safe_code' => $code,
                'last_success_at' => $complete ? $ticket->startedAt : $work->last_success_at, 'cursor' => $nextCursor, 'source_cursor' => $nextSourceCursor ?? $ticket->sourceCursor]);
            // Projection pointer changes do not alter watch configuration/revision or freshness.
            DB::table('domains_watch_names')->where('id', $ticket->watchId)->update(['last_observation_id' => $row->id]);
            return true;
        });
    }
    public function defer(WatchDnsTicket $t,int $now): void
    {
        DB::table('domains_watch_work')->where('watch_id',$t->watchId)->where('kind',$t->kind)->where('claim',$t->claim)
            ->update(['claim'=>null,'lease_until'=>null,'due_at'=>max($now,$t->startedAt)+60,'safe_code'=>'budget_exhausted']);
    }
    /** Current read-only identity for projections; never claims work or performs DNS. */
    public function currentStamp(int $watchId, int $userId, int $sourceCursor = 0): ?string
    {
        return $this->target($watchId, $userId, false, $sourceCursor)['stamp'] ?? null;
    }
    private function target(int $watchId, int $userId, bool $lock = true, int $sourceCursor = 0): ?array
    {
        // Discovery owns source enrollment. Capture a bounded native prefix before watch/work locks.
        $candidate = WatchName::whereKey($watchId)->where('user_id', $userId)->first();
        if ($candidate === null || $candidate->state !== 'active' || WatchManagedTarget::exists($userId,$candidate->canonical_name)) { return null; }
        [$sources, $overflow] = $this->sources($watchId, $sourceCursor, false);
        if ($sources->isEmpty() || !User::whereKey($userId)->when($lock, fn($q) => $q->lockForUpdate())->exists()) { return null; }
        $orderIds = Service::whereIn('id', $sources->pluck('service_id'))->pluck('order_id');
        $orders = Order::whereIn('id', $orderIds)->orderBy('id')->when($lock, fn($q) => $q->lockForUpdate())->get()->keyBy('id');
        $services = Service::whereIn('id', $sources->pluck('service_id'))->orderBy('id')->when($lock, fn($q) => $q->lockForUpdate())->get()->keyBy('id');
        $products = Product::whereIn('id', $services->pluck('product_id'))->orderBy('id')->when($lock, fn($q) => $q->lockForUpdate())->get()->keyBy('id');
        $maps = WatchProductMap::whereIn('id', $sources->pluck('map_id'))->orderBy('id')->when($lock, fn($q) => $q->lockForUpdate())->get()->keyBy('id');
        $profile = WatchProfile::whereKey($candidate->ns_profile_id)->when($lock, fn($q) => $q->lockForUpdate())->first();
        $watch = WatchName::whereKey($watchId)->where('user_id', $userId)->when($lock, fn($q) => $q->lockForUpdate())->first();
        [$current, $currentOverflow] = $this->sources($watchId, $sourceCursor, $lock);
        if ($watch === null || $watch->state !== 'active' || $watch->managed_domain_id !== null || $profile === null || !$profile->enabled ||
            (int) $watch->ns_profile_id !== (int) $profile->id || $currentOverflow !== $overflow || $current->pluck('id')->all() !== $sources->pluck('id')->all()) { return null; }
        // Profile agreement is watch-wide even when hostname/native capture rotates a bounded cohort.
        if (DB::table('domains_watch_sources as s')->join('domains_watch_product_maps as m','m.id','=','s.map_id')
            ->where('s.watch_id',$watchId)->where('s.state','watch')->whereNotNull('m.expected_ns_profile_id')
            ->where('m.expected_ns_profile_id','<>',$profile->id)->exists()) { return null; }
        $hosts = [$watch->canonical_name]; $evidence = [];
        foreach ($current as $source) {
            $service = $services[$source->service_id] ?? null; $map = $maps[$source->map_id] ?? null; $order = $orders[$service?->order_id] ?? null; $product = $products[$service?->product_id] ?? null;
            if ($service === null || $order === null || (int) $order->user_id !== $userId || $map === null || $product === null || !$map->enabled || $service->status !== 'active' ||
                (int) $service->user_id !== $userId || (int) $source->user_id !== $userId || (int) $service->product_id !== (int) $map->product_id ||
                (int) $map->revision !== (int) $source->map_revision || $source->registrable_name !== $watch->canonical_name ||
                ($source->hostname !== $watch->canonical_name && !str_ends_with((string) $source->hostname, '.'.$watch->canonical_name))) { return null; }
            $values = $service->properties()->where('key', $map->property_key)->when($lock, fn($q) => $q->lockForUpdate())->pluck('value');
            if ($values->count() !== 1 || !is_string($values[0])) { return null; }
            try { if (DnsQuery::hostname($values[0]) !== $source->hostname) { return null; } } catch (\InvalidArgumentException) { return null; }
            $hosts[] = $source->hostname;
            $evidence[] = [$source->only(['id','user_id','service_id','map_id','map_revision','hostname','registrable_name','watch_id','managed_domain_id','state','source_digest','revision']), $order->id, $order->user_id, $service->id, $service->user_id, $service->product_id, $service->status,
                $map->revision, $map->property_key, $map->expected_ns_profile_id, $values[0], $product->server_id];
            if ($map->expected_ns_profile_id !== null && (int) $map->expected_ns_profile_id !== (int) $profile->id) { return null; }
        }
        $hosts = array_values(array_diff(array_unique($hosts), [$watch->canonical_name])); sort($hosts, SORT_STRING);
        array_unshift($hosts, $watch->canonical_name);
        $stamp = hash('sha256', json_encode([$watch->id, $watch->user_id, $watch->canonical_name, $watch->revision, $watch->psl_revision,
            $watch->expected_dns, $overflow, $profile->id, $profile->revision, $profile->profile(), $evidence], JSON_THROW_ON_ERROR));
        return ['watch' => $watch, 'profile' => $profile, 'hosts' => $hosts, 'stamp' => $stamp, 'overflow' => $overflow,
            'next_source_cursor' => $overflow ? (int) $current->first()->id : 0];
    }
    /** Overflow uses one rotating source plus apex; never an unbounded native lock fanout. */
    private function sources(int $watchId, int $cursor, bool $lock): array
    {
        $base = WatchSource::where('watch_id',$watchId)->where('state','watch');
        $prefix = (clone $base)->orderBy('id')->limit(101)->get(); $overflow = $prefix->count() > 100;
        if (!$overflow) { return [$lock ? (clone $base)->orderBy('id')->limit(101)->lockForUpdate()->get() : $prefix, false]; }
        $next = (clone $base)->where('id','>',$cursor)->orderBy('id')->first();
        $id = $next?->id ?? $prefix->first()->id;
        return [(clone $base)->whereKey($id)->when($lock, fn($q) => $q->lockForUpdate())->get(), true];
    }

}
