<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The Auto-renew row's visible line: how the renewal is paid, with amount, method and date when known (P-WRD-2, P-WRD-3).
 * Staff and customers read the same words. Dates come from the saved billing calendar, never guessed from expiry. */
final class RenewalPayment
{
    /** The owner's Auto-renew line. How the invoice is paid has its own Auto Pay row, so this says only whether one is sent. */
    public static function ownerLine(bool $on, bool $complimentary = false): string
    {
        if (!$on) { return self::line(false, null, false, null, []); }
        return $complimentary ? 'Complimentary. Nothing is charged.' : 'We send a renewal invoice each year.';
    }

    /** The owner's Auto Pay row, in the same words as the service page: state, card, and the one link label. */
    public static function autoPayState(bool $autoPay, ?string $method): string
    {
        if (!$autoPay) { return 'Off. You pay each renewal invoice.'; }
        $method = trim((string) $method);
        if (preg_match('/^link\b/i', $method)) { return 'On, Link wallet'; }
        if (preg_match('/^(?:card|visa|mastercard|amex|american express|discover|jcb|diners(?: club)?|unionpay|cartes bancaires|eftpos(?: au)?) (?:(?:\*{4}|•{4})\h+|ending )([0-9]{4})$/iuD', $method, $last)) {
            return 'On, card ending '.$last[1];
        }
        return 'On';
    }

    /** The forecast uses the regular quote and saved issue date. An issued invoice or a missed issue date takes precedence. */
    public static function scheduledInvoice(OwnedDomain $domain, ?DomainSummary $summary, ?string $annual): ?string
    {
        if (!$domain->autoPay || $domain->departureSuspected || $domain->renewalInvoice || !$summary
            || $summary->renewalInvoiceMissing || $summary->renewalInvoiceOverdue) { return null; }
        $issue = $domain->renewalSchedule['issue'] ?? null;
        if ($issue !== $summary->renewalIssueAt || $issue === null) { return null; }
        $amount = Fact::money($annual);
        $day = Fact::day($issue);
        return $amount && $day ? 'We expect a '.$amount->text.' invoice on '.$day->text.'. Auto Pay is set to pay any balance.' : null;
    }

    /** Manage while the domain or the account switch is On, so the link never offers to turn on what is already on. */
    public static function autoPayLink(bool $autoPay, bool $account = false): string
    {
        return $autoPay || $account ? 'Manage Auto Pay' : 'Turn on Auto Pay';
    }

    /** @param array{issue?:string,due?:string} $calendar */
    public static function line(bool $on, ?string $amount, bool $autoPay, ?string $method, array $calendar, bool $complimentary = false): string
    {
        if (!$on) { return 'No renewal invoice will be sent. An invoice already sent can still be paid.'; }
        if ($complimentary) { return 'Complimentary. Nothing is charged.'; }
        $issue = isset($calendar['issue']) ? Fact::day($calendar['issue'])?->text : null;
        $due = isset($calendar['due']) ? Fact::day($calendar['due'])?->text : null;
        if ($autoPay) {
            $method = trim((string) $method) ?: 'the saved payment method';
            return $issue !== null && $amount !== null
                ? 'Paid by Auto Pay. We charge '.$method.' '.$amount.' on '.$issue.'.'
                : 'Paid by Auto Pay. We charge '.$method.' when the renewal invoice is sent.';
        }
        return $issue !== null && $due !== null && $amount !== null
            ? 'Paid by an invoice we email on '.$issue.' for '.$amount.', due '.$due.'.'
            : 'Paid by an invoice we email before the domain expires.';
    }
}
