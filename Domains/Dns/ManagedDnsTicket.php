<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Captured public read identity. No operation, billing or DNS-write grant. */
final readonly class ManagedDnsTicket
{
    public function __construct(public PublicSignalsTarget $target, public string $kind, public string $claim,
        public int $startedAt, public string $stamp, public ?int $profileId, public ?int $profileRevision,
        public int $configRevision, public ?RoutingProfile $profile, public array $hostnames, public int $cursor,
        public int $interval, public int $sourceCursor=0, public int $nextSourceCursor=0, public bool $sourceOverflow=false, public ?string $presentationStamp=null) {}
}
