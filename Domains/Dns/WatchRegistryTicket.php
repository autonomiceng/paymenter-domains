<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
final readonly class WatchRegistryTicket
{
    public function __construct(public int $watchId,public int $userId,public string $name,public string $stamp,
        public string $claim,public int $startedAt,public int $sourceCursor,public int $nextSourceCursor) {}
}
