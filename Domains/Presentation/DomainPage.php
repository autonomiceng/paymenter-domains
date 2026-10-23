<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Carbon\CarbonImmutable;
/** The client domain page: its one notice and the Renewal card rows, from the page DTOs alone. */
final class DomainPage
{
    /** The one notice at the top of the page: text, tone and its one action, or null when nothing needs doing. */
    public static function notice(OwnedDomain $d, ?string $support = null, ?DomainSummary $s = null): ?array
    {
        $setup = $d->actions['setup'] ?? null; $phase = $d->transfer['phase'] ?? null;
        $movedAt = isset($s?->noticeDates['dns']) ? ' on '.CarbonImmutable::createFromTimestampUTC((int) $s->noticeDates['dns'])->format('M j, Y') : '';
        $contact = $support ? ['label' => 'Contact support', 'url' => $support] : null;
        return match (true) {
            $d->syncPaused => ['tone' => 'warn', 'text' => 'Sync paused: the demo registrar is off. Nameservers show when it resumes.', 'action' => $contact],
            $d->setupPending => ['tone' => 'info', 'text' => 'We’re confirming this domain. Changes and renewals start after that. Contact support if it expires soon.', 'action' => null],
            in_array('watch.ns_changed', $s?->stateKeys ?? [], true) => ['tone' => 'warn', 'text' => $d->name.' stopped using Beco nameservers'.$movedAt.'.',
                'action' => isset($d->management['nameservers']) ? ['label' => 'Change nameservers', 'url' => $d->actions['show'].'?edit=nameservers#edit-nameservers'] : $contact],
            $setup && $phase === 'cutoff' && ($d->setup['contacts'] ?? false) => ['tone' => 'warn', 'text' => 'The auth code deadline passed. Update your details so we can finish the transfer.', 'action' => ['label' => 'Update details', 'url' => $setup]],
            $setup && ($d->setup['defaults'] ?? false) && !($d->setup['contacts'] ?? false) => ['tone' => 'info', 'text' => 'Choose nameservers before registration starts, or we use Beco nameservers.', 'action' => ['label' => 'Choose nameservers', 'url' => $setup]],
            (bool) $setup => ['tone' => 'warn', 'text' => 'Finish setup to start registration.', 'action' => ['label' => 'Finish setup', 'url' => $setup]],
            isset($d->actions['code']) && ($d->transfer['open'] ?? false) && !($d->transfer['received'] ?? false) => ['tone' => 'warn', 'text' => 'Enter the auth code from your current registrar.', 'action' => ['label' => 'Enter auth code', 'url' => $d->actions['code']]],
            default => null,
        };
    }
    /** Expires: the date with its tone and one sub line, or Missing with its cause or fix. */
    public static function expires(OwnedDomain $d, ?DomainSummary $s, ?string $support): array
    {
        if ($d->expiry === null) {
            $list = $s ? DomainListRow::expires($s) : null;
            return ['missing' => true, 'cause' => $list['cause'] ?? null, 'fix' => $list ? $list['fix'] : ($support ? ['label' => 'Contact support', 'url' => $support] : null)];
        }
        $days = $d->daysLeft ?? $s?->daysLeft ?? (int) CarbonImmutable::now('UTC')->startOfDay()->diffInDays(CarbonImmutable::parse($d->expiry, 'UTC'), false);
        $note = match (true) {
            ($d->renewNow['status'] ?? $d->renewalInvoice['state'] ?? null) === 'paid' => 'Updates when the renewal completes',
            $d->renewalOff !== null => 'Cancels '.self::date($d->expiry),
            $days > 0 && $days <= 30 => 'In '.$days.($days === 1 ? ' day' : ' days'),
            default => null,
        };
        return ['missing' => false, 'tone' => $days <= 0 ? 'bad' : ($days <= 30 ? 'warn' : null), 'note' => $note];
    }
    /** Next invoice: the current renewal invoice's state (Due + Pay invoice, Paid, or Missing with its cause and fix), else the planned date for a domain that renews. Null when it does not apply. */
    public static function nextInvoice(OwnedDomain $d, ?DomainSummary $s, ?string $support): ?array
    {
        $state = $d->renewNow['status'] ?? (isset($d->renewNow['invoice']) ? 'pending' : null) ?? $d->renewalInvoice['state'] ?? null;
        $contact = $support ? ['label' => 'Contact support', 'url' => $support] : null;
        $due = $d->renewalDue ?? $d->renewalInvoice['due'] ?? null;
        if ($state === null && ($d->renewal !== 'On' || $d->setupPending)) { return null; }
        return match (true) {
            $state === 'pending' => ['value' => $due ? 'Due '.self::date($due) : 'Due', 'action' => isset($d->renewNow['invoice']) ? ['label' => 'Pay invoice', 'url' => $d->renewNow['invoice']] : null],
            $state === 'paid' => ['value' => 'Paid'],
            $state === 'cancelled' => ['missing' => true, 'cause' => 'Invoice cancelled', 'fix' => $contact],
            $state !== null => ['missing' => true, 'cause' => 'Renewal being checked', 'fix' => $contact],
            $d->plannedRenewalDue !== null => ['value' => self::date($d->plannedRenewalDue)],
            $s?->renewalDueAt !== null => ['value' => self::date($s->renewalDueAt)],
            (bool) $s?->renewalInvoiceMissing => ['missing' => true, 'cause' => 'Not issued yet'.($s->renewalIssueAt ? '. Expected '.self::date($s->renewalIssueAt).'.' : '')],
            $d->expiry === null => ['missing' => true, 'fix' => $contact],
            default => null,
        };
    }
    public static function date(string $date): string
    {
        return CarbonImmutable::parse(substr($date, 0, 10), 'UTC')->format('M j, Y');
    }
}
