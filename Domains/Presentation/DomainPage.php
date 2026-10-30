<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Carbon\CarbonImmutable;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
/** The client domain page: its one notice and the Renewal card rows, from the page DTOs alone. */
final class DomainPage
{
    /** The cause on each value while reads are stopped. The page notice carries the one fix, so the rows stay calm. */
    public const TEAM_CHECK = 'Our team needs to check this domain. '.Missing::NO_ACTION;
    public static function checkHeld(OwnedDomain $d): bool
    { return collect($d->management)->contains(fn ($section) => $section['recordCheckHeld'] ?? false); }
    /** The one notice at the top of the page: text, tone and its one action, or null when nothing needs doing. */
    public static function notice(OwnedDomain $d, ?string $support = null, ?DomainSummary $s = null): ?array
    {
        $setup = $d->actions['setup'] ?? null; $phase = $d->transfer['phase'] ?? null;
        $movedAt = isset($s?->noticeDates['dns']) ? ' on '.CarbonImmutable::createFromTimestampUTC((int) $s->noticeDates['dns'])->format('M j, Y') : '';
        $contact = $support ? ['label' => 'Contact support', 'url' => $support] : null;
        $order = $d->order['state'] ?? null; $word = ($d->order['kind'] ?? null) === 'transfer' ? 'transfer' : 'registration';
        return match (true) {
            $d->syncPaused => ['tone' => 'warn', 'text' => 'Sync paused: the demo registrar is off. Nameservers show when it resumes.', 'action' => $contact],
            $d->setupPending => ['tone' => 'info', 'text' => 'We’re confirming this domain. Changes and renewals start after that. If it expires soon, let us know.', 'action' => $contact],
            $order === 'unpaid' => ['tone' => 'warn', 'text' => 'This order isn’t paid yet. Pay the invoice to start the '.$word.'.', 'action' => ['label' => 'Pay invoice', 'url' => $d->order['invoice'], 'button' => true]],
            $order === 'cancelled' => ['tone' => 'warn', 'text' => 'This order was cancelled. Ask us to start it again.', 'action' => $contact],
            $order === 'failed' => ['tone' => 'bad', 'text' => 'This '.$word.' did not finish. Ask us to start it again.', 'action' => $contact],
            in_array('watch.ns_changed', $s?->stateKeys ?? [], true) => ['tone' => 'warn', 'text' => $d->name.' stopped using '.DnsSignalLabels::nameservers().$movedAt.'.',
                'action' => isset($d->management['nameservers']) ? ['label' => 'Change nameservers', 'url' => $d->actions['show'].'?edit=nameservers#edit-nameservers'] : $contact],
            $setup && $phase === 'cutoff' && ($d->setup['contacts'] ?? false) => ['tone' => 'warn', 'text' => 'The auth code deadline passed. Update your details so we can finish the transfer.', 'action' => ['label' => 'Update details', 'url' => $setup]],
            $setup && ($d->setup['defaults'] ?? false) && !($d->setup['contacts'] ?? false) => ['tone' => 'info', 'text' => 'Choose nameservers before registration starts, or we use '.DnsSignalLabels::nameservers().'.', 'action' => ['label' => 'Choose nameservers', 'url' => $setup]],
            (bool) $setup => ['tone' => 'warn', 'text' => 'Finish setup to start registration.', 'action' => ['label' => 'Finish setup', 'url' => $setup]],
            isset($d->actions['code']) && ($d->transfer['open'] ?? false) && !($d->transfer['received'] ?? false) => ['tone' => 'warn', 'text' => 'Enter the auth code from your current registrar.', 'action' => ['label' => 'Enter auth code', 'url' => $d->actions['code']]],
            $order === 'paid' && !isset($d->order['next']) => ['tone' => 'info', 'text' => 'Paid. We’re finishing the '.$word.'. There’s nothing you need to do.', 'action' => $contact],
            self::checkHeld($d) => ['tone' => 'warn', 'text' => 'We can’t read this domain’s details right now. Our team needs to check it.', 'action' => $contact],
            $d->changesUnavailable => ['tone' => 'info', 'text' => 'Changes to this domain are on hold right now. Try again later.', 'action' => $contact],
            default => null,
        };
    }
    /** Expires: the date with its tone and one sub line, or Missing with its cause or fix. */
    public static function expires(OwnedDomain $d, ?DomainSummary $s, ?string $support): array
    {
        if ($d->expiry === null) {
            if ($order = DomainListRow::orderExpires($d->order, $support)) { return $order; }
            if (self::checkHeld($d)) { return ['missing' => true, 'cause' => self::TEAM_CHECK, 'fix' => null]; }
            $list = $s ? DomainListRow::expires($s) : null;
            return ['missing' => true, 'cause' => $list['cause'] ?? Missing::fills('after the next sync'), 'fix' => isset($list['cause']) ? $list['fix'] : null];
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
        $contact = Missing::support($support);
        $due = $d->renewalDue ?? $d->renewalInvoice['due'] ?? null;
        if ($state === null && ($d->renewal !== 'On' || $d->setupPending)) { return null; }
        return match (true) {
            $state === 'pending' => ['value' => $due ? 'Due '.self::date($due) : 'Due', 'action' => isset($d->renewNow['invoice']) ? ['label' => 'Pay invoice', 'url' => $d->renewNow['invoice']] : null],
            $state === 'paid' => ['value' => 'Paid'],
            $state === 'cancelled' => ['missing' => true, 'cause' => 'Invoice cancelled', 'fix' => $contact],
            $state !== null => ['missing' => true, 'cause' => 'Renewal being checked', 'fix' => $contact],
            $d->plannedRenewalDue !== null => ['value' => self::date($d->plannedRenewalDue)],
            $s?->renewalDueAt !== null => ['value' => self::date($s->renewalDueAt)],
            (bool) $s?->renewalInvoiceMissing => ['missing' => true, 'cause' => 'Not issued yet'.($s->renewalIssueAt ? '. Expected '.self::date($s->renewalIssueAt).'.' : ''), 'fix' => $contact],
            $d->expiry === null => ['missing' => true, 'cause' => self::checkHeld($d) ? self::TEAM_CHECK : Missing::fills('after the next sync'), 'fix' => null],
            default => null,
        };
    }
    /** Auth code: Saved with its link, or Missing with the step for this transfer phase. Null while it can still be entered: the page notice asks for it. */
    public static function authCode(OwnedDomain $d, ?string $support): ?array
    {
        if (!isset($d->actions['code'])) { return null; }
        $open = (bool) ($d->transfer['open'] ?? false);
        if ($d->transfer['received'] ?? false) { return ['missing' => false, 'action' => ['label' => $open ? 'Change auth code' : 'View transfer', 'url' => $d->actions['code']]]; }
        if ($open) { return null; }
        $setup = $d->actions['setup'] ?? null;
        return ['missing' => true, 'fix' => $setup && ($d->transfer['phase'] ?? null) === 'cutoff' && ($d->setup['contacts'] ?? false) ? ['label' => 'Update details', 'url' => $setup] : Missing::support($support)];
    }
    public static function date(string $date): string
    {
        return CarbonImmutable::parse(substr($date, 0, 10), 'UTC')->format('M j, Y');
    }
}
