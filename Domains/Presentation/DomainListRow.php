<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Carbon\CarbonImmutable;
use Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts;
/** Cells of the client Domains list, from the summary alone. Domains your hosting uses renew at their registrar. */
final class DomainListRow
{
    private const WAITS = ['import' => 'when the import is confirmed', 'registration' => 'when the registration finishes', 'transfer' => 'when the transfer finishes'];
    /** "Hosting: {first}" or "Hosting: {first} and {n} more". */
    public static function hosting(DomainSummary $s): ?string
    {
        if (!$s->relatedServices) { return null; }
        $more = count($s->relatedServices) - 1 + $s->relatedOverflow;
        return 'Hosting: '.$s->relatedServices[0]['label'].($more > 0 ? ' and '.$more.' more' : '');
    }
    /** Missing Expires while an order is open: the pay button, that it's paid, or support. Null when the order doesn't decide it.
     * $funding false: the page notice already says Complimentary, so the cause doesn't say it again. */
    public static function orderExpires(array $order, ?string $support, bool $funding = true): ?array
    {
        $word = ($order['kind'] ?? null) === 'transfer' ? 'transfer' : 'registration';
        // A complimentary order has nothing paid, so it says what it is, as the page notice and receipt do.
        $complimentary = $funding && ($order['complimentary'] ?? false);
        $basis = ($order['complimentary'] ?? false) ? ($funding ? 'Complimentary. ' : '') : 'Paid. ';
        return match ($order['state'] ?? null) {
            'unpaid' => ['missing' => true, 'cause' => ucfirst($word).' starts after you pay', 'fix' => ['label' => 'Pay invoice', 'url' => $order['invoice'], 'button' => true]],
            'paid' => ['missing' => true, ...match ($order['next'] ?? null) {
                'awaiting_code' => ['cause' => $basis.'Add the auth code to start the transfer.', 'fix' => null],
                'setup_required' => ['cause' => $basis.'Finish setup to start the '.$word.'.', 'fix' => null],
                // With no notice above it, a complimentary row says what the page notice says.
                default => ['cause' => $complimentary ? $basis.'We’re finishing the '.$word.'. There’s nothing you need to do.' : Missing::fills(self::WAITS[$word]), 'fix' => null],
            }],
            'cancelled' => isset($order['credit']) ? ['missing' => false, 'tone' => null, 'note' => null] : ['missing' => true, 'cause' => 'Order cancelled', 'fix' => Missing::support($support)],
            'failed' => ['missing' => true, 'cause' => ucfirst($word).' did not finish', 'fix' => Missing::support($support)],
            default => null,
        };
    }
    /** A date with its tone (bad once passed, warn within 30 days) and "In N days", or a pending registration, or Missing with its cause or fix. */
    public static function expires(DomainSummary $s): array
    {
        if ($s->expiry === null) {
            if ($s->statusState === 'cancelled') { return ['missing' => false, 'tone' => null, 'note' => null]; } // Nothing left to fill in.
            if ($order = self::orderExpires($s->order, $s->urls['support'] ?? null)) { return $order; }
            $wait = self::WAITS[$s->expiryWaits ?? ''] ?? null;
            $cause = $s->kind === 'watch' ? 'Check at your registrar' : ($wait ? Missing::fills($wait) : null);
            return ['missing' => true, 'cause' => $cause, 'fix' => $cause === null ? Missing::support($s->urls['support'] ?? null) : null];
        }
        $days = $s->daysLeft;
        $soon = $days !== null && $days > 0 && $days <= 30;
        return ['missing' => false, 'tone' => $days !== null && $days <= 0 ? 'bad' : ($soon ? 'warn' : null), 'note' => $soon ? 'In '.$days.($days === 1 ? ' day' : ' days') : null];
    }
    /** "At your registrar", or Auto-renew on/off with the price, the cancel date or a missing next invoice. */
    public static function renewal(DomainSummary $s): array
    {
        if ($s->kind === 'watch') { return ['value' => 'At your registrar', 'note' => null, 'invoiceMissing' => false, 'invoiceCause' => null, 'invoiceFix' => null]; }
        if ($s->renewalOffShown()) {
            return ['value' => 'Off', 'note' => $s->expiry ? 'Cancels '.CarbonImmutable::parse(substr($s->expiry, 0, 10), 'UTC')->format('M j, Y') : null, 'invoiceMissing' => false, 'invoiceCause' => null, 'invoiceFix' => null];
        }
        $complimentary = $s->renewalPrice === ComplimentaryAccounts::LABEL;
        // Complimentary is said once per row: a pending complimentary order's Expires already says it.
        $price = !$s->renewalInvoiceMissing && $s->renewalPrice && !($complimentary && str_starts_with(self::expires($s)['cause'] ?? '', 'Complimentary. '))
            ? $s->renewalPrice.($complimentary ? '' : '/yr') : null;
        return ['value' => 'On', 'note' => $s->renewalInvoicePaid ? 'Next invoice: Paid' : $price, 'invoiceMissing' => $s->renewalInvoiceMissing,
            'invoiceCause' => $s->renewalInvoiceMissing ? 'Not issued yet' : null, 'invoiceFix' => $s->renewalInvoiceMissing ? Missing::support($s->urls['support'] ?? null) : null];
    }
}
