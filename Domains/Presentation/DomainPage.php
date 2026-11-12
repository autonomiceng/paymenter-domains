<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Carbon\CarbonImmutable;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement, RenewalRepeatWait};
/** The client domain page: its one notice and the Renewal card rows, from the page DTOs alone. */
final class DomainPage
{
    public const INITIAL_READ = 'Checking with the registrar. This usually takes a few minutes. Changes open when the check finishes.';
    /** The setup step only lets the owner choose nameservers before a paid registration starts: it goes ahead without them. */
    public static function optionalSetup(OwnedDomain $d): bool
    { return isset($d->actions['setup']) && ($d->setup['defaults'] ?? false) && !($d->setup['contacts'] ?? false); }
    public static function initialRead(OwnedDomain $d): bool
    { return collect($d->management)->contains(fn ($section) => $section['family']['initial_read_pending'] ?? false); }
    /** The cause on each value while reads are stopped. The page notice carries the one fix, so the rows stay calm. */
    public const TEAM_CHECK = 'Our team needs to check this domain. '.Missing::NO_ACTION;
    public const NOTHING_TO_DO = 'There’s nothing you need to do.';
    /** How long a transfer takes, said wherever the customer waits for one. */
    public const TRANSFER_TIME = 'Most transfers finish within 5 to 7 days.';
    /** The work under way on a paid order, with the time frame for a transfer. */
    public static function finishing(string $word): string
    { return 'We’re finishing the '.$word.'.'.($word === 'transfer' ? ' '.self::TRANSFER_TIME : ''); }
    /** The page notice while reads are stopped. When it shows, the rows need not repeat it. */
    public const READ_HELD = 'We can’t read this domain’s details right now. '.DomainStatus::ON_IT.'.';
    public static function readHeldSaid(?array $notice): bool
    { return ($notice['text'] ?? null) === self::READ_HELD; }
    /** True when the page notice already tells the customer there is nothing for them to do. */
    public static function noActionSaid(?array $notice): bool
    { return str_contains($notice['text'] ?? '', self::NOTHING_TO_DO) || str_contains($notice['text'] ?? '', Missing::NO_ACTION); }
    /** True when the page may still say there is nothing to do: not said yet, and no step for the customer in the status or notice. */
    private static function reassure(OwnedDomain $d, ?array $notice): bool
    { return !self::noActionSaid($notice) && !DomainStatus::asksCustomer($d->status) && !in_array($notice['tone'] ?? null, ['warn', 'bad'], true) && !($notice['asks'] ?? false); }
    public static function checkHeld(OwnedDomain $d): bool
    { return collect($d->management)->contains(fn ($section) => $section['recordCheckHeld'] ?? false); }
    /** Held or unconfirmed work without a scheduled status check needs review instead of a completion estimate. */
    public static function orderHeld(array $order): bool
    { return in_array($order['progress'] ?? null, ['held', 'uncertain'], true) && !($order['retrying'] ?? false); }
    /** The one notice at the top of the page: text, tone and its one action, or null when nothing needs doing.
     * Amber (warn) means the customer has a step. Work that is ours reads blue (ours()), as Under review does. */
    public static function notice(OwnedDomain $d, ?string $support = null, ?DomainSummary $s = null): ?array
    {
        $setup = $d->actions['setup'] ?? null; $phase = $d->transfer['phase'] ?? null;
        $movedAt = isset($s?->noticeDates['dns']) ? ' on '.CarbonImmutable::createFromTimestampUTC((int) $s->noticeDates['dns'])->format('M j, Y') : '';
        $contact = $support ? ['label' => 'Contact support', 'url' => $support] : null;
        $order = $d->order['state'] ?? null; $word = ($d->order['kind'] ?? null) === 'transfer' ? 'transfer' : 'registration';
        $renewalCheck=$d->renewNow['check']??$d->renewalInvoice['check']??null;
        $nameservers = self::nameserverNotice($d, $s, $movedAt, $contact);
        $notice = match (true) {
            // A nameserver step restores service, so it stays with its action and the renewal check is said beside it.
            $renewalCheck!==null && $nameservers!==null => [...$nameservers, 'text' => $nameservers['text'].' '.($renewalCheck['brief'] ?? $renewalCheck['body'])],
            $renewalCheck!==null => ['tone'=>'info','text'=>$renewalCheck['body'],'action'=>$contact],
            $d->syncPaused => self::ours('Sync paused: the demo registrar is off. Nameservers show when it resumes.', $contact),
            $d->setupPending => self::ours('We’re confirming this domain. Changes and renewals start after that. If it expires soon, let us know.', $contact),
            $order === 'unpaid' => ['tone' => 'warn', 'text' => 'This order isn’t paid yet. Pay the invoice to start the '.$word.'.', 'action' => ['label' => 'Pay invoice', 'url' => $d->order['invoice'], 'button' => true]],
            $order === 'cancelled' && isset($d->order['return']) => ['tone' => 'warn', 'text' => 'This order was cancelled. '.$d->order['return'], 'action' => $contact],
            $order === 'cancelled' && isset($d->order['credit']) => ['tone' => 'warn', 'text' => 'This order was cancelled. We added what you paid to account credit. You can apply it on an invoice.', 'action' => null],
            $order === 'cancelled' && ($d->order['unpaid'] ?? false) => ['tone' => 'info', 'text' => 'This order was cancelled. Nothing was charged.', 'action' => null],
            $order === 'cancelled' => ['tone' => 'warn', 'text' => 'This order was cancelled. Ask us to start it again.', 'action' => $contact],
            $order === 'failed' => ['tone' => 'bad', 'text' => 'This '.$word.' did not finish. Ask us to start it again.', 'action' => $contact],
            in_array('watch.ns_changed', $s?->stateKeys ?? [], true) => $nameservers,
            $setup && $phase === 'cutoff' && ($d->setup['contacts'] ?? false) => ['tone' => 'warn', 'text' => 'The auth code deadline passed. Update your details so we can finish the transfer.', 'action' => ['label' => 'Update details', 'url' => $setup]],
            $setup && self::optionalSetup($d) => ['tone' => 'info', 'text' => 'Choose nameservers before registration starts, or we use '.(implode(', ', $d->setup['defaultNameservers'] ?? []) ?: 'the registrar’s default nameservers').'.', 'action' => ['label' => 'Choose nameservers', 'url' => $setup]],
            (bool) $setup => ['tone' => 'warn', 'text' => 'Finish setup to start registration.', 'action' => ['label' => 'Finish setup', 'url' => $setup]],
            isset($d->actions['code']) && ($d->transfer['open'] ?? false) && !($d->transfer['received'] ?? false) => ['tone' => 'warn', 'text' => 'Enter the auth code from your current registrar.', 'action' => ['label' => 'Enter auth code', 'url' => $d->actions['code']]],
            ($d->transfer['notice']['tone'] ?? null) === 'warning' => ['tone' => 'warn', 'text' => $d->transfer['notice']['title'].' '.$d->transfer['notice']['body'], 'action' => $contact],
            $order === 'paid' && isset($d->order['check']) => ['tone' => $d->order['check']['tone'] ?? 'warn', 'text' => $d->order['check']['title'].'. '.$d->order['check']['body'], 'action' => $contact],
            $order === 'paid' && self::orderHeld($d->order) => self::ours('This '.$word.' is under review. We’ll check it and update you.', $contact),
            // A complimentary order has nothing paid, so it says what it is, as its receipt does.
            $order === 'paid' && in_array($d->order['next'] ?? null, [null, 'setup_optional'], true) => self::ours((($d->order['complimentary'] ?? false) ? 'Complimentary. ' : 'Paid. ').self::finishing($word).' '.self::NOTHING_TO_DO, $contact),
            // An Off switch is why changes stop, so its own notice below wins over a stopped read, which the rows already say.
            self::checkHeld($d) && !($d->changesUnavailable && $d->changesRefused === DomainManagement::SWITCH_OFF) => self::ours(self::READ_HELD, $contact),
            $d->statusReason === DomainStatus::LATE => ['tone' => 'bad', 'text' => 'This domain has expired. '.DomainStatus::LATE.'.', 'action' => $contact],
            $d->dnsCorrection => $nameservers,
            self::initialRead($d) => ['tone'=>'info','text'=>self::INITIAL_READ,'action'=>null],
            $d->changesUnavailable && $d->changesRefused === DomainManagement::SWITCH_OFF => ['tone' => 'info', 'text' => 'Changes are on hold: DNS, nameservers and contacts are Off. Contact us to turn them on.', 'action' => $contact],
            $d->changesUnavailable && is_int($d->changesRefused) => ['tone' => 'info', 'text' => 'Changes are on hold until we finish checking an earlier request. '.self::NOTHING_TO_DO, 'action' => $contact],
            $d->changesUnavailable => self::ours(self::HELD[$d->changesRefused] ?? 'Changes to this domain are paused while we check it with the registrar. '.self::NOTHING_TO_DO, $contact),
            default => null,
        };
        // Funding describes the issued order even when setup or a registrar check supplies its next step.
        if ($order === 'paid' && ($d->order['complimentary'] ?? false) && $notice !== null && !str_starts_with($notice['text'], 'Complimentary. ')) {
            $notice['text'] = 'Complimentary. '.$notice['text'];
        }
        return $notice;
    }
    /** The nameserver step: the domain stopped using ours, or the registration settings were not applied. Null without one.
     * asks: the customer has this step, even in a blue notice, so the page does not also say no action is needed. */
    private static function nameserverNotice(OwnedDomain $d, ?DomainSummary $s, string $movedAt, ?array $contact): ?array
    {
        $change = isset($d->management['nameservers']) ? ['label' => 'Change nameservers', 'url' => $d->actions['show'].'?edit=nameservers#edit-nameservers'] : null;
        if (in_array('watch.ns_changed', $s?->stateKeys ?? [], true)) {
            return ['tone' => 'warn', 'text' => $d->name.($s->dnsPreviouslyMatched?' stopped using ':' does not use ').DnsSignalLabels::nameservers().($s->dnsPreviouslyMatched?$movedAt:'').'.', 'action' => $change ?? $contact, 'asks' => true];
        }
        if (!$d->dnsCorrection) { return null; }
        return ['tone'=>'info','text'=>'The registered nameservers do not match the registration settings. '.($change?'Set up DNS with your provider, then change nameservers.':($contact?'Set up DNS with your provider, then contact support to change nameservers.':'Set up DNS with your provider. Nameserver changes are currently unavailable.')),'action'=>$change ?? $contact,'asks'=>true];
    }
    /** Why changes wait, in the customer's words, by the refusal reason DomainManagement gives. */
    private const HELD = [
        'Prior operation unresolved.' => 'Changes open once an earlier change finishes. '.self::NOTHING_TO_DO,
        'Current records required.' => 'Changes open once we’ve read this domain’s records from the registrar. '.self::NOTHING_TO_DO,
        'Management lifecycle held.' => 'Changes are on hold for this domain right now.',
    ];
    /** A notice for work that is ours: blue, with the way to reach us. */
    private static function ours(string $text, ?array $contact): array
    { return ['tone' => 'info', 'text' => $text, 'action' => $contact]; }
    /** Expires: the date with its tone and one sub line, or Missing with its cause or fix. */
    public static function expires(OwnedDomain $d, ?DomainSummary $s, ?string $support): array
    {
        if ($d->expiry === null) {
            if ($s?->abandoned) { return ['missing' => false, 'tone' => null, 'note' => null]; }
            // Only a paid held order cancelled by staff keeps this step until its refund is recorded.
            if (($d->order['refund_pending'] ?? false) === true) {
                return DomainListRow::orderExpires($d->order, $support);
            }
            if (self::rejected($d)) {
                $transfer = $d->rejectedAcquisition === 'transfer' || $d->statusReason === DomainStatus::STATES['transfer_rejected'][1];
                return ['missing' => false, 'tone' => null, 'value' => $transfer ? 'Not transferred' : 'Not registered',
                    'note' => trim(($transfer ? 'The domain stays at your current registrar. ' : '').(($d->order['returned'] ?? false) ? ($d->order['return'] ?? '') : (self::REFUND[$d->refundTo ?? ''] ?? '')))];
            }
            $said = str_starts_with(self::notice($d, $support, $s)['text'] ?? '', 'Complimentary. ');
            if ($order = DomainListRow::orderExpires($d->order, $support, funding: !$said)) { return $order; }
            if (self::checkHeld($d)) { return ['missing' => true, 'cause' => self::TEAM_CHECK, 'fix' => null]; }
            $list = $s ? DomainListRow::expires($s) : null;
            if (isset($list['value'])) { return $list; }
            return ['missing' => true, 'cause' => $list['cause'] ?? Missing::fills('after the next sync'), 'fix' => isset($list['cause']) ? $list['fix'] : null];
        }
        $days = $d->daysLeft ?? $s?->daysLeft ?? (int) CarbonImmutable::now('UTC')->startOfDay()->diffInDays(CarbonImmutable::parse($d->expiry, 'UTC'), false);
        $note = match (true) {
            ($d->renewNow['status'] ?? $d->renewalInvoice['state'] ?? null) === 'paid' => self::EXPIRY_PENDING,
            $d->renewalOff !== null => 'Cancels '.self::date($d->expiry),
            $days > 0 && $days <= 30 => 'In '.$days.($days === 1 ? ' day' : ' days'),
            default => null,
        };
        return ['missing' => false, 'tone' => $days < 0 ? 'bad' : ($days <= 30 ? 'warn' : null), 'note' => $note];
    }
    /** The renewal invoice's state in words, the same on the domain page and the Domains list (DomainStates::cycle). */
    /** The open renewal invoice's last charge failed, whether Auto Pay or a card made it. */
    /** Beside the current expiry while a paid renewal is under way, so it never reads as the new date. */
    public const EXPIRY_PENDING = 'Current expiry date. It updates when the renewal completes.';
    public const DECLINED = 'The last payment failed. Pay the invoice with another card.';
    /** An unread registrar record on the customer page: a calm word per row, and one note above the rows. */
    public const NOT_READ = Missing::NOT_READ;
    public const NOT_READ_NOTE = 'Nameservers, contacts and DNS records show here once we’ve read them from the registrar.';
    /** A paid cycle has no next invoice yet, so its row names the invoice it shows. */
    public const PAID_LABEL = 'Renewal invoice';
    public const INVOICE = ['paid' => 'Paid', 'review' => 'Under review', 'cancelled' => 'Invoice cancelled', 'closed' => 'Renewal did not finish'];
    /** What happens to the money once the registrar rejected the order, by OwnedDomain::$refundTo. */
    public const REFUND = ['original' => 'Any refund goes back to your original payment method.', 'credit' => 'Any refund goes to your account credit.',
        'split' => 'Any refund goes back to your original payment method where it can, and to your account credit otherwise.',
        'credited' => 'We added what you paid to account credit.', 'returned' => 'We are returning what you paid to your original payment method.'];
    /** The registrar rejected the registration or transfer, so no expiry will ever fill in. */
    private static function rejected(OwnedDomain $d): bool
    { return in_array($d->rejectedAcquisition, ['register', 'transfer'], true) || in_array($d->statusReason, [DomainStatus::STATES['registration_rejected'][1], DomainStatus::STATES['transfer_rejected'][1]], true); }
    /** Next invoice: the current renewal invoice's state (Due + Pay invoice, Paid, Under review in blue while we check it, or Missing with its cause and fix), else the day the next invoice is issued and when it is due. Null when it does not apply. */
    public static function nextInvoice(OwnedDomain $d, ?DomainSummary $s, ?string $support): ?array
    {
        $state = $d->renewNow['status'] ?? (isset($d->renewNow['invoice']) ? 'pending' : null) ?? $d->renewalInvoice['state'] ?? null;
        $contact = Missing::support($support);
        $due = $d->renewalDue ?? $d->renewalInvoice['due'] ?? null;
        if ($state === null && ($d->renewal !== 'On' || $d->setupPending || self::rejected($d))) { return null; }
        return match (true) {
            $state === 'pending' => ['value' => $due ? ($d->earlyRenewal ? RequestStatus::earlyRenewal($due) : 'Due '.self::date($due)) : 'Due',
                ...(($d->renewNow['declined'] ?? false) ? ['note' => self::DECLINED] : []), 'action' => isset($d->renewNow['invoice']) ? ['label' => 'Pay invoice', 'url' => $d->renewNow['invoice']] : null],
            $state === 'paid' => ['label' => self::PAID_LABEL, 'value' => self::INVOICE['paid'], ...(($goes = $d->renewNow['goes'] ?? $d->renewalInvoice['goes'] ?? null) ? ['note' => RenewalRepeatWait::NOTHING.' '.RenewalRepeatWait::GOES, 'at' => $goes] : [])],
            $state === 'closed' => ['value' => self::INVOICE['closed'], 'action' => $contact],
            $state === 'cancelled' => ['missing' => true, 'cause' => self::INVOICE['cancelled'], 'fix' => $contact],
            // The row says there is nothing to do unless the page notice already says so,
            // or the status or notice gives the customer a step (an expired domain: contact support).
            $state !== null => ['value' => self::INVOICE['review'], 'tone' => 'info', 'note' => 'Renewal being checked.'.(self::reassure($d, self::notice($d, $support, $s)) ? ' '.Missing::NO_ACTION : '')],
            // An expired domain keeps a renewal invoice that was never issued, so the page never says it issues on a passed date.
            $s !== null && ($s->renewalInvoiceMissing || $s->renewalInvoiceOverdue) => ['missing' => true, 'cause' => 'Not issued yet'.($s->renewalIssueAt ? '. Expected '.self::date($s->renewalIssueAt).'.' : ''), 'fix' => $contact],
            $s?->renewalIssueAt !== null => ['value' => 'Issues '.self::date($s->renewalIssueAt).($s->renewalDueAt ? '. Due '.self::date($s->renewalDueAt).'.' : '')],
            ($d->plannedRenewalDue ?? $s?->renewalDueAt) !== null => ['value' => 'Due '.self::date($d->plannedRenewalDue ?? $s->renewalDueAt)],
            $d->expiry === null => ['missing' => true, 'cause' => self::checkHeld($d) ? self::TEAM_CHECK : Missing::fills('after the next sync'), 'fix' => null],
            default => null,
        };
    }
    /** Retained local order facts only; no registrar reads or registration retries. */
    public static function orderProgress(OwnedDomain $d): array
    {
        return ['pending' => $d->setupPending || in_array($d->status, ['Pending registration', 'Pending transfer'], true),
            'state' => hash('sha256', json_encode([$d->status, $d->statusReason, $d->setupPending, $d->order, $d->expiry], JSON_THROW_ON_ERROR))];
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
    /** The tone of a word in History: what ended well, what broke, what is the customer's to do, and what is still ours. */
    public static function stepTone(string $word): string
    {
        return match ($word) {
            'Completed', 'Paid' => 'ok',
            'Failed' => 'bad',
            'Unpaid', 'Action required' => 'warn',
            'Cancelled' => 'muted',
            default => 'info',
        };
    }
    public static function date(string $date): string
    {
        return CarbonImmutable::parse(substr($date, 0, 10), 'UTC')->format('M j, Y');
    }
}
