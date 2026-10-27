<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Worker-local capture. Possession grants no registration or native-service authority. */
final readonly class WatchDnsTicket
{
    public function __construct(public int $watchId, public int $userId, public string $kind, public string $claim,
        public int $startedAt, public string $stamp, public int $profileId, public int $profileRevision,
        public string $pslRevision, public int $watchRevision, public RoutingProfile $profile,
        public array $hostnames, public int $cursor, public int $interval, public int $sourceCursor = 0, public int $nextSourceCursor = 0, public bool $sourceOverflow = false, public ?string $presentationStamp = null) {}
}
