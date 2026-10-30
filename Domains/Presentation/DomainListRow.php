<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Carbon\CarbonImmutable;
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
    /** Missing Expires while an order is open: the pay button, that it's paid, or support. Null when the order doesn't decide it. */
    public static function orderExpires(array $order, ?string $support): ?array
    {
        $word = ($order['kind'] ?? null) === 'transfer' ? 'transfer' : 'registration';
        return match ($order['state'] ?? null) {
            'unpaid' => ['missing' => true, 'cause' => ucfirst($word).' starts after you pay', 'fix' => ['label' => 'Pay invoice', 'url' => $order['invoice'], 'button' => true]],
            'paid' => ['missing' => true, ...match ($order['next'] ?? null) {
                'awaiting_code' => ['cause' => 'Paid. Add the auth code to start the transfer.', 'fix' => null],
                'setup_required' => ['cause' => 'Paid. Finish setup to start the '.$word.'.', 'fix' => null],
                default => ['cause' => 'Paid. We’re finishing the '.$word.'. '.Missing::NO_ACTION, 'fix' => Missing::support($support)],
            }],
            'cancelled' => ['missing' => true, 'cause' => 'Order cancelled', 'fix' => Missing::support($support)],
            'failed' => ['missing' => true, 'cause' => ucfirst($word).' did not finish', 'fix' => Missing::support($support)],
            default => null,
        };
    }
    /** A date with its tone (bad once passed, warn within 30 days) and "In N days", or a pending registration, or Missing with its cause or fix. */
    public static function expires(DomainSummary $s): array
    {
        if ($s->expiry === null) {
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
            return ['value' => 'Auto-renew off', 'note' => $s->expiry ? 'Cancels '.CarbonImmutable::parse(substr($s->expiry, 0, 10), 'UTC')->format('M j, Y') : null, 'invoiceMissing' => false, 'invoiceCause' => null, 'invoiceFix' => null];
        }
        return ['value' => 'Auto-renew on', 'note' => !$s->renewalInvoiceMissing && $s->renewalPrice ? $s->renewalPrice.'/yr' : null, 'invoiceMissing' => $s->renewalInvoiceMissing,
            'invoiceCause' => $s->renewalInvoiceMissing ? 'Not issued yet' : null, 'invoiceFix' => $s->renewalInvoiceMissing ? Missing::support($s->urls['support'] ?? null) : null];
    }
}
