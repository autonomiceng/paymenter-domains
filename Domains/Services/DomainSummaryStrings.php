<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
final class DomainSummaryStrings
{
    public static function all(): array
    {
        $company=DnsSignalLabels::company(config('settings.company_name'));
        return DnsSignalLabels::strings($company)+['domain.registration.platform'=>$company.' Registration','domain.registration.external'=>'External Registration','domain.registration.unknown'=>'Registration details unavailable',
            'adopt.pending'=>'Registration setup pending','adopt.pending_notice'=>'We need to verify this registration before handling renewals.',
            'adopt.provenance'=>'Adopted registration','adopt.renewal_off'=>'Renewal off','adopt.activity'=>'Registration adopted',
            'watch.paused'=>'Checks paused','watch.retired'=>'Watch ended','watch.not_checked'=>'Not checked yet','watch.stale'=>'Checks are out of date',
            'watch.partial'=>'Checks partly complete','watch.unknown'=>'We could not confirm the current details. We will check again.',
            'watch.no_coverage'=>'This registry does not provide a usable public expiry date.',
            'watch.not_registered'=>'The public registry did not return a registration. Contact your registration provider to check.',
            'domain.expired'=>'Registry expiry reached','domain.redemption'=>'Registration needs review','watch.expired'=>'Registry expiry reached','watch.redemption'=>'Registration needs review','watch.ns_changed'=>'Nameservers changed',
            'watch.ns_changed_notice'=>'{domain} no longer uses the '.$company.' nameservers (observed {date}). If you did not expect this,',
            'watch.ns_pending'=>'Checking a nameserver change','ns_changed'=>'Nameservers changed','ns_pending'=>'Checking a nameserver change','dns_unavailable'=>'DNS details unavailable','expiry_unavailable'=>'Public registration details unavailable','registry_expiry_reached'=>'Registry expiry reached','domain.transfer'=>'Transfer in progress','domain.cancelled'=>'Order cancelled','domain.awaiting_payment'=>'Awaiting payment','domain.processing'=>'Processing','domain.active'=>'Active','domain.pending'=>'Registration in progress','domain.review'=>'Needs attention'];
    }
}
