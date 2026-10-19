<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsContext,DnsQuery};
/** Public DNS facts only. Every exchange consumes the supplied job context. */
interface DnsReader
{
    public function read(DnsQuery $query, DnsContext $context): DnsAnswer;
}
