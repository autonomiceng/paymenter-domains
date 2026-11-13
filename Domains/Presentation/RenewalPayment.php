<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The Auto-renew row's visible line: how the renewal is paid, with amount, method and date when known (P-WRD-2, P-WRD-3).
 * Staff and customers read the same words. Dates come from the saved billing calendar, never guessed from expiry. */
final class RenewalPayment
{
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
