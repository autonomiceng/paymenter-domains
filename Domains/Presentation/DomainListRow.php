<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Carbon\CarbonImmutable;
/** Cells of the client Domains list, from the summary alone. Domains your hosting uses renew at their registrar. */
final class DomainListRow
{
    private const WAITS = ['import' => 'Shows when the import is confirmed', 'registration' => 'Shows when the registration completes', 'transfer' => 'Shows when the transfer completes'];
    /** "Hosting: {first}" or "Hosting: {first} and {n} more". */
    public static function hosting(DomainSummary $s): ?string
    {
        if (!$s->relatedServices) { return null; }
        $more = count($s->relatedServices) - 1 + $s->relatedOverflow;
        return 'Hosting: '.$s->relatedServices[0]['label'].($more > 0 ? ' and '.$more.' more' : '');
    }
    /** A date with its tone (bad once passed, warn within 30 days) and "In N days", or Missing with its cause or fix. */
    public static function expires(DomainSummary $s): array
    {
        if ($s->expiry === null) {
            $cause = $s->kind === 'watch' ? 'Check at your registrar' : (self::WAITS[$s->expiryWaits ?? ''] ?? null);
            $support = $s->urls['support'] ?? null;
            return ['missing' => true, 'cause' => $cause, 'fix' => $cause === null && $support ? ['label' => 'Contact support', 'url' => $support] : null];
        }
        $days = $s->daysLeft;
        $soon = $days !== null && $days > 0 && $days <= 30;
        return ['missing' => false, 'tone' => $days !== null && $days <= 0 ? 'bad' : ($soon ? 'warn' : null), 'note' => $soon ? 'In '.$days.($days === 1 ? ' day' : ' days') : null];
    }
    /** "At your registrar", or Auto-renew on/off with the price, the cancel date or a missing next invoice. */
    public static function renewal(DomainSummary $s): array
    {
        if ($s->kind === 'watch') { return ['value' => 'At your registrar', 'note' => null, 'invoiceMissing' => false]; }
        if ($s->renewalOffShown()) {
            return ['value' => 'Auto-renew off', 'note' => $s->expiry ? 'Cancels '.CarbonImmutable::parse(substr($s->expiry, 0, 10), 'UTC')->format('M j, Y') : null, 'invoiceMissing' => false];
        }
        return ['value' => 'Auto-renew on', 'note' => !$s->renewalInvoiceMissing && $s->renewalPrice ? $s->renewalPrice.'/yr' : null, 'invoiceMissing' => $s->renewalInvoiceMissing];
    }
}
