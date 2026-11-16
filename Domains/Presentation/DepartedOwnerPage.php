<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use App\Models\Invoice;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DepartedHistory, DepartedPage, DepartureConfirm};
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, WatchName};
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks, HoldingDeparture};
/** The customer page of a confirmed departure (mocks 02, 02b, 02c), read from its saved record and the confirmation's
 * history entry only. It is read-only: the one link out is the way back, transfer or register again, on the existing
 * order pages. */
final class DepartedOwnerPage
{
    /** @return ?array{deleted:bool,company:string,back:array{0:string,1:string},support:string,note:string,rows:list<array>,history:list<array>} */
    public static function of(Domain $d, ?DomainSummary $summary): ?array
    {
        $r = DepartedPage::record($d);
        if ($r === null) { return null; }
        $deleted = $r['reason'] === 'deleted'; $company = DnsSignalLabels::company() ?: 'us';
        $zone = new \DateTimeZone(config('domains.time_zone') ?: config('app.timezone'));
        $at = is_string($r['confirmed_at'] ?? null) && ($t = strtotime($r['confirmed_at'])) !== false ? (new \DateTimeImmutable('@'.$t))->setTimezone($zone) : null;
        $rows = [];
        if ($d->created_at !== null && $at !== null) {
            $rows[] = ['label' => 'With '.$company, 'value' => \Carbon\Carbon::parse($d->created_at)->setTimezone($zone)->format('M j, Y').' to '.$at->format('M j, Y')];
        }
        if ($d->provider_expires_at !== null) { $rows[] = ['label' => 'Last known expiry', 'value' => $d->provider_expires_at->format('M j, Y'), 'note' => 'From our last registrar read.']; }
        $hosting = $summary && count($summary->relatedServices) === 1 ? ($summary->relatedServices[0]['url'] ?? null) : null;
        // Other nameservers: customer surfaces say nothing about DNS (README, DNS lines).
        match (DepartureChecks::savedDnsCase($d, $r['dns_case'] ?? null)) {
            'registrar' => $rows[] = ['label' => 'DNS records', 'value' => $deleted ? 'Stopped at deletion' : 'Stopped at the transfer',
                'note' => $deleted ? "This domain used our registrar's DNS. Its records stopped working when the domain was deleted." : "This domain used our registrar's DNS. Its records stopped working when the transfer completed. If you haven't already, set up DNS at your new host."],
            'hosting' => $rows[] = ['label' => 'DNS records', 'value' => 'On your hosting plan', 'note' => 'DNS records on your hosting plan keep working while the plan is active.', 'link' => $hosting ? ['Open hosting service', $hosting] : null],
            default => null,
        };
        // Monitoring is said only when the monitored domain is running (it never promises monitoring that is off).
        $savedWatch = DepartedHistory::entry($d)['watch'] ?? null;
        $watch = WatchName::whereKey((int) ($savedWatch['id'] ?? 0))->where('user_id', $d->user_id)
            ->where('canonical_name', $d->domain)->whereNull('managed_domain_id')->where('state', 'active')->first();
        // The watch's own page opens only while no live holding of the same name takes precedence over it, so link only then.
        if ($watch !== null && $d->user !== null && (new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($d->user, 'watch', (int) $watch->id) !== null) {
            $rows[] = ['label' => 'Monitoring', 'value' => 'Monitored as an external domain', 'link' => ['Open monitored domain', route('domains.checks.show', ['kind' => 'watch', 'id' => (int) $watch->id])]];
        }
        $reason = HoldingDeparture::REASONS[$r['reason']];
        $history = $at === null ? [] : [['label' => 'Departure', 'state' => $deleted ? 'Deleted' : 'Transferred away', 'note' => $reason, 'date' => $at->format(DATE_ATOM)]];
        foreach (is_array($r['invoices'] ?? null) ? $r['invoices'] : [] as $o) {
            if (!is_array($o) || !is_int($o['invoice_id'] ?? null) || !in_array($o['outcome'] ?? null, ['cancelled_unpaid', 'credited'], true) || $at === null) { continue; }
            $invoice = Invoice::whereKey($o['invoice_id'])->where('user_id', $d->user_id)->first();
            if ($invoice === null) { continue; }
            $note = 'Domain '.($deleted ? 'deleted at the registry' : 'transferred away').'.'.($o['outcome'] === 'credited' ? ' '.DepartureConfirm::money((int) ($o['credit_minor'] ?? 0)).' to account credit.' : '');
            $history[] = ['label' => 'Invoice', 'number' => (string) ($invoice->number ?? $invoice->id), 'url' => route('invoices.show', $invoice), 'state' => 'Cancelled', 'note' => $note, 'date' => $at->format(DATE_ATOM)];
        }
        $name = ['name' => $d->domain];
        return ['deleted' => $deleted, 'company' => $company,
            'back' => $deleted ? ['Register again', route('domains.domains.search', $name)] : ['Transfer back to '.$company, route('domains.domains.transfer', $name)],
            'support' => $deleted ? "Didn't expect this? " : "Didn't move this domain? ",
            'note' => 'This domain is no longer with '.$company.'. Its invoices and history stay here.', 'rows' => $rows, 'history' => $history];
    }
}
