<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** The one DomainStatus state for a customer domain. The Domains list, the domain page, the service card and
 * /services all resolve through here, so a domain carries the same label on every page.
 * Each method returns [state, reason]; a null reason keeps the status set's default. */
final class DomainStates
{
    /** Auto-renew off turns Active into Action required this many days before expiry. */
    public const WINDOW = 30;
    /** Held acquisition codes the customer clears by finishing setup. */
    private const SETUP = ['contact_details_required', 'nameservers_required'];
    /** @return array{0:string,1:?string} */
    public static function managed(Domain $d, ?Operation $op, string $today, bool $renewalOff): array
    {
        if ($d->adoptionPending()) { return ['adoption_pending', null]; }
        if ($op && self::current($op, $d) && in_array($d->status, ['registration_pending', 'transfer_pending', 'needs_review'], true)) {
            $register = $op->action === 'register';
            $state = match (true) {
                in_array($op->state, ['awaiting_payment', 'awaiting_code'], true) => [$op->state, null],
                $op->state === 'held' && in_array($op->safe_code, self::SETUP, true) => ['setup_required', null],
                $op->state === 'held' => $register ? ['registration_pending', null] : ['held', 'Transfer on hold'],
                $op->state === 'uncertain' => $register ? ['registration_pending', null] : ['uncertain', 'Transfer not confirmed'],
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
    /** A monitored domain: registry and nameserver findings first; Active only with a current registry expiry; otherwise no status.
     * @return array{0:string,1:?string}|null */
    public static function watch(array $stateKeys, ?string $expiry, bool $current): ?array
    {
        foreach (['watch.not_registered', 'watch.redemption', 'watch.expired', 'watch.ns_changed'] as $key) {
            if (in_array($key, $stateKeys, true)) { return [$key, $key === 'watch.ns_changed' ? self::nameservers() : null]; }
        }
        return $expiry && $current ? ['active', null] : null;
    }
    /** "Not using {company} nameservers"; the status set's default when no company name is configured. */
    private static function nameservers(): ?string
    {
        $company = DnsSignalLabels::company(config('settings.company_name'));
        return $company === 'us' ? null : 'Not using '.$company.' nameservers';
    }
    /** The acquisition still describes this holding: same service, server, registrar, generation and account. */
    private static function current(Operation $op, Domain $d): bool
    {
        return (int) $op->service_id === (int) $d->service_id && (int) $op->server_id === (int) $d->server_id && $op->registrar_key === $d->registrar_key
            && (int) $op->authority_generation === (int) $d->authority_generation && $op->account_fingerprint === $d->account_fingerprint;
    }
}
