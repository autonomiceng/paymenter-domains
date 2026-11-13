<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Visible renewal terms, shared by both readers. Dates are the saved billing calendar, never inferred from expiry. */
final class RenewalPayment
{
    public static function line(bool $on, ?string $amount, bool $autoPay, ?string $method, array $calendar, bool $complimentary = false): string
    {
        if (!$on) { return 'No future renewal invoice is emailed. Any invoice already issued stays payable.'; }
        if ($complimentary) { return 'Complimentary. No payment is collected from you.'; }
        $issue = isset($calendar['issue']) ? Fact::day($calendar['issue'])?->text : null;
        $due = isset($calendar['due']) ? Fact::day($calendar['due'])?->text : null;
        if ($autoPay) {
            $method = trim((string) $method) ?: 'your saved payment method';
            if ($issue !== null && $amount !== null) { return 'Auto Pay charges '.$method.' '.$amount.' when we issue the invoice on '.$issue.'.'; }
            return 'Auto Pay charges '.$method.'. The invoice shows the amount and charge date.';
        }
        if ($issue !== null && $due !== null && $amount !== null) { return 'We email an invoice for '.$amount.' on '.$issue.'. Pay it by '.$due.'.'; }
        return 'We email a renewal invoice. You pay it by its due date.';
    }
}
