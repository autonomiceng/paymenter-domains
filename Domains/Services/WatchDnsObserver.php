<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsCollector,DnsContext};
/** Explicit internal composition only. No jobs, routes, bindings or scheduled activation ship here. */
final class WatchDnsObserver
{
    private \Closure $clock;
    public function __construct(private WatchDnsChecks $checks, private DnsReader $reader, ?callable $clock = null)
    { $this->clock = $clock === null ? static fn(): int => time() : \Closure::fromCallable($clock); }
    public function observe(int $watchId, int $userId, string $kind): bool
    {
        if (DB::transactionLevel() !== 0) { return false; }
        $ticket = $this->checks->begin($watchId, $userId, $kind, ($this->clock)());
        if ($ticket === null) { return false; }
        [$reading, $cursor, $sourceCursor] = (new DnsCollector($this->reader))->collect($ticket, new DnsContext());
        return $this->checks->finish($ticket, $reading, ($this->clock)(), $cursor, $sourceCursor);
    }
}
