<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** The one DomainStatus state for a customer domain. The Domains list, the domain page, the service card and
 * /services all resolve through here, so a domain carries the same label on every page.
 * managed() and watch() return [state, reason]; a null reason keeps the status set's default.
 * project() builds the whole customer state (status, order, renewal and its invoice) the list and the page share. */
final class DomainStates
{
    /** Auto-renew off turns Active into Action required this many days before expiry. */
    public const WINDOW = 30;
    /** @return array{0:string,1:?string} */
    public static function managed(Domain $d, ?Operation $op, string $today, bool $renewalOff): array
    {
        if ($d->adoptionPending()) { return ['adoption_pending', null]; }
        if ($op && self::current($op, $d) && in_array($d->status, ['registration_pending', 'transfer_pending', 'needs_review'], true)) {
            $register = $op->action === 'register';
            $state = match (true) {
                in_array($op->state, ['awaiting_payment', 'awaiting_code'], true) => [$op->state, null],
                \Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::readsAs($op->state, $op->safe_code) === 'setup_required' => ['setup_required', null],
                $op->state === 'held' => $register ? ['registration_held', null] : ['held', 'Transfer on hold'],
                $op->state === 'uncertain' => $register ? ['registration_unconfirmed', null] : ['uncertain', 'Transfer not confirmed'],
                default => null,
            };
            if ($state) { return $state; }
        }
        if ($d->status !== 'active') { return [$d->status, null]; }
        $expiry = $d->provider_expires_at?->format('Y-m-d');
        return match (true) {
            $expiry === null => ['active_no_expiry', null],
            $expiry <= $today => ['expired', null],
            $renewalOff && $expiry <= (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))->modify('+'.self::WINDOW.' days')->format('Y-m-d') => ['renewal_off_expiring', null],
            default => ['active', null],
        };
    }
    /** One owned domain's customer state from batch-loaded inputs. The Domains list and the domain page both call this. */
    public static function project(Domain $d, DomainStateInputs $in, \DateTimeImmutable $now): DomainState
    {
        $today = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'); $stops = $in->stops($d);
        if ($d->adoptionPending()) { return new DomainState(['adoption_pending', null], [], null, $stops, '', true); }
        $op = $in->acquisition($d); $policy = $stops['policy']; $off = RenewalStop::stopped($stops);
        $order = $op ? OwnedDomains::order($d, $op, $in->invoice($op->invoice_id, $d), OwnedDomains::closed($in->service($d)?->status, $in->stopped($d))) : [];
        $status = $policy === null ? ['needs_review', null] : OwnedDomains::orderStatus(self::managed($d, $op, $today, $off), $d, $order);
        $renewal = $policy !== null && $d->status === 'active' ? ($off ? 'Off' : 'On') : '';
        // Unreadable renewal settings show no renewal invoice: the status already says we are checking the domain.
        try { $cycle = $policy === null ? [] : self::cycle($d, $in, $now); } catch (\Throwable) { $cycle = ['current' => ['status' => 'review']]; }
        try { $calendar = $d->provider_expires_at ? DomainBilling::calendarFor(['domain' => $d, 'settings' => $in->settings($d)]) : null; } catch (\Throwable) { $calendar = null; }
        // Billing stops issuing on the clock's own date, so the projection uses that date too, not UTC.
        $imported = $d->provenance === 'adopted' && $renewal === 'On' && !isset($cycle['current']) && $d->provider_expires_at !== null && ($calendar === null || $now->format('Y-m-d') >= $calendar['due']);
        return new DomainState($status, $order, $op, $stops, $renewal, $off, $cycle['current'] ?? null, $cycle['retained'] ?? null, $cycle['due'] ?? null, $cycle['early'] ?? false, $calendar, $imported);
    }
    /** This expiry's renewal invoice as billing sees it: current (its pending invoice link or an owner status), or retained once
     * settled when nothing replaces it, with the current invoice's due date. A renewal without an expiry is ours to check. */
    public static function cycle(Domain $d, DomainStateInputs $in, \DateTimeImmutable $now): array
    {
        if (!$d->provider_expires_at) { return ['current' => $in->renewed($d) ? ['status' => 'review'] : null]; }
        $cycle = $in->cycle($d);
        if (!$cycle) { return []; }
        $check = $in->check($cycle);
        $review = ['status' => 'review', ...(($check['tone'] ?? null) === 'info' ? ['check' => $check] : [])];
        if ($cycle->safe_code === 'renewal_cancelled_unpaid' || RenewalReplacements::reviewed($cycle, $d)) {
            // A retired or refunded invoice permits a replacement only when billing's proof holds.
            if ($in->unproven($d)) { return ['current' => ['status' => 'review']]; }
            return ['retained' => RenewalCycles::closedEarly($cycle) ? null : ['status' => $cycle->safe_code === 'renewal_cancelled_unpaid' ? 'cancelled' : 'review']];
        }
        $l = $in->ledger($d, collect([$cycle]));
        try { $invoice = DomainBilling::existingInvoice($cycle, $l, allowCancelled: true); } catch (\Throwable) { return ['current' => $review]; }
        $due = $invoice->due_at?->format('Y-m-d');
        if ($invoice->status === 'pending' && RenewalCycles::expiredInvoice($cycle, $now)) {
            return ['current' => ['status' => RenewalCycles::canRetireUnpaid($cycle, $l) ? 'cancelled' : 'review'], 'due' => $due];
        }
        $current = match (self::issuedState($cycle, $invoice, $d, $l)) {
            'pending' => ['invoice' => route('invoices.show', $invoice)],
            'paid' => ['status' => 'paid', ...self::goes($cycle)],
            'closed' => ['status' => 'closed'],
            'cancelled' => ['status' => 'cancelled'],
            default => $review };
        return ['current' => $current, 'due' => $due, 'early' => isset($current['invoice']) && RenewalCycles::early($cycle, $now) !== null];
    }
    /** A checked renewal invoice as the owner reads it: pending (pay it), paid, closed, cancelled, or review while we check it.
     * A cancelled invoice reads cancelled only when its ledger $l proves the renewal unpaid and unsent; otherwise we check it. */
    public static function issuedState(Operation $cycle, \App\Models\Invoice $invoice, Domain $domain, array $l): string
    {
        return match (true) {
            $invoice->status === 'cancelled' => RenewalCycles::canRetireUnpaid($cycle, $l) ? 'cancelled' : 'review',
            $cycle->state === 'awaiting_payment' && $invoice->status === 'pending' => 'pending',
            $cycle->funding_basis === 'complimentary' && FundsWaits::closedRenewal($cycle, $domain) => 'closed',
            $invoice->status === 'paid' && in_array($cycle->state, ['awaiting_payment', 'queued', 'running', 'pending', 'succeeded'], true) => 'paid',
            default => 'review',
        };
    }
    /** goes: the fixed time a paid renewal waiting out Enom's 24-hour repeat refusal goes to the registrar (ISO, UTC). */
    public static function goes(Operation $cycle): array
    {
        $until = RenewalRepeatWait::until($cycle);
        return $until ? ['goes' => $until->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM)] : [];
    }
    /** A monitored domain: registry and nameserver findings first; Active only with a current registry expiry; otherwise no status.
     * @return array{0:string,1:?string}|null */
    public static function watch(array $stateKeys, ?string $expiry, bool $current): ?array
    {
        foreach (['watch.not_registered', 'watch.redemption', 'watch.expired', 'watch.ns_changed'] as $key) {
            if (in_array($key, $stateKeys, true)) { return [$key, $key === 'watch.ns_changed' ? self::nameservers() : null]; }
        }
        return $expiry && $current ? ['active', null] : null;
    }
    /** "Not using {company} nameservers". */
    private static function nameservers(): string
    { return 'Not using '.DnsSignalLabels::nameservers(); }
    /** The acquisition still describes this holding: same service, server, registrar, generation and account. */
    private static function current(Operation $op, Domain $d): bool
    {
        return (int) $op->service_id === (int) $d->service_id && (int) $op->server_id === (int) $d->server_id && $op->registrar_key === $d->registrar_key
            && (int) $op->authority_generation === (int) $d->authority_generation && $op->account_fingerprint === $d->account_fingerprint;
    }
}
