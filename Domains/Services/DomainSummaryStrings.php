<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
final class DomainSummaryStrings
{
    public static function all(): array
    {
        $company=DnsSignalLabels::company();
        return DnsSignalLabels::strings($company)+['domain.registration.platform'=>'Registered with us','domain.registration.external'=>'External Registration','domain.registration.unknown'=>'Registration details unavailable','domain.details.partial'=>'Some details unavailable',
            'adopt.pending_notice'=>'We need to verify this registration before handling renewals.',
            'watch.paused'=>'Checks paused','watch.retired'=>'Watch ended','watch.not_checked'=>'Not checked yet','watch.stale'=>'Checks are out of date',
            'watch.partial'=>'Checks partly complete','watch.unknown'=>'We could not confirm the current details. We will check again.',
            'watch.no_coverage'=>'This registry does not provide a usable public expiry date.',
            'watch.not_registered'=>'The public registry didn’t return a registration. Contact your registrar to check.',
            'watch.expired'=>'Registry expiry reached','watch.redemption'=>'Registration needs review','watch.ns_changed'=>'Nameservers changed',
            'watch.ns_changed_notice'=>'{domain} no longer uses '.DnsSignalLabels::nameservers().' (observed {date}). If you did not expect this,',
            'watch.ns_pending'=>'Checking a nameserver change'];
    }
}
