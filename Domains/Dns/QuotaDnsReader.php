<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Services\PublicDnsQuota;
/** Per-wire reservation, shared by both target families; denial is local saturation. */
final class QuotaDnsReader implements DnsReader
{
    public bool $saturated=false;
    public function __construct(private DnsReader $reader, private PublicDnsQuota $quota, private string $token, private \Closure $clock) {}
    public function read(DnsQuery $query, DnsContext $context): DnsAnswer
    {
        if (DB::transactionLevel()!==0 || !$context->live() || !$this->quota->reserve($this->token,($this->clock)())) {
            $this->saturated=true; return DnsAnswer::unknown($query,'budget_exhausted');
        }
        return $this->reader->read($query,$context);
    }
}
