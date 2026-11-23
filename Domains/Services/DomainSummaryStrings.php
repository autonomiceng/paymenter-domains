<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
final class DomainSummaryStrings
{
    public static function all(): array
    {
        return ['adopt.pending_notice'=>'We need to verify this registration before handling renewals.'];
    }
}
