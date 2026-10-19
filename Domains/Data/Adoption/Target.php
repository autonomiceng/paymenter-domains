<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data\Adoption;
use Paymenter\Extensions\Servers\Domains\Data\{Environment, Query};
final readonly class Target
{
    public function __construct(public string $name, public int $serviceId, public int $customerId,
        public int $serverId, public Environment $environment)
    {
        Query::requireName($name);
        if (min($serviceId, $customerId, $serverId) < 1) { throw new \InvalidArgumentException('Exact adoption target required.'); }
    }
}
