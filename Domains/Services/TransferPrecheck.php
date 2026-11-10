<?php
// Adapted from Enom 444f31c TransferOrders/CartOrdering; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Data\{Action, CanonicalName, Query, RequestContext, SaleSelection};
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
/** External one-year code transfers only. No dispatch or new registry-schema acceptance. */
final readonly class TransferPrecheck
{
    public function __construct(private Rdap $rdap) {}
    /** Local check that transfers are open for the selection's ending, before any registry facts. Search uses it to offer a transfer link. */
    public static function endingOpen(SaleSelection $s): bool
    {
        $endings = Server::find($s->serverId)?->settings()->where('key', 'domains_transfer_endings')->value('value');
        return in_array($s->registrar, ['enom', 'namesilo'], true) && self::endingAccepted($s->tld, self::openEndings($s, $endings));
    }
    /** A saved list narrows transfers. NameSilo otherwise uses the validated seller offer. */
    private static function openEndings(SaleSelection $s, mixed $endings): array
    {
        return is_string($endings) ? array_map('trim', explode(',', $endings))
            : ($s->registrar === 'namesilo' ? [$s->tld] : EnomTestTransfer::endings($s->serverId));
    }
    private static function endingAccepted(string $tld, array $open): bool
    {
        return in_array($tld, $open, true) && !in_array(substr($tld, (int) strrpos('.'.$tld, '.')), ['uk', 'ca', 'eu', 'ai', 'de'], true);
    }
    public static function rules(SaleSelection $s, bool $locked = false): array
    {
        $tld = Tld::where(['server_id' => $s->serverId, 'tld' => $s->tld])->when($locked, static fn ($q) => $q->lockForUpdate())->firstOrFail();
        $settings = Server::findOrFail($s->serverId)->settings()->whereIn('key',
            ['domains_transfer_endings', 'domains_transfer_creation_lock_days', 'domains_transfer_previous_lock_days'])->when($locked, static fn ($q) => $q->lockForUpdate())->get()->keyBy('key');
        $open = self::openEndings($s, $settings->get('domains_transfer_endings')?->value);
        // Worker selections carry no cart receipt; both paths use the same current provider facts.
        $facts = \Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::facts($tld->catalogue_facts);
        if (!$tld->catalogue_converted || $tld->registrar_key !== $s->registrar
            || $tld->account_fingerprint !== $s->fingerprint || !$facts
            || !$facts->fresh(app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now())) {
            throw new \DomainException('Current transfer facts unavailable.');
        }
        $schema = $facts->registryFields;
        if (!in_array($s->registrar, ['enom', 'namesilo'], true) || $s->action !== 'transfer' || $s->term !== 1
            || !self::endingAccepted($s->tld, $open)
            || !RegistryInputs::supported($s->registrar, $s->tld, $schema)) { throw new \DomainException('Transfer ending is not accepted.'); }
        $days = [];
        foreach (['domains_transfer_creation_lock_days', 'domains_transfer_previous_lock_days'] as $key) {
            $v = $settings->get($key)?->value ?? 60;
            if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 0 || (int) $v > 365) { throw new \DomainException('Invalid transfer lock policy.'); }
            $days[] = (int) $v;
        }
        return $days;
    }
    public function check(string $name, SaleSelection $s, RegistrarDriver $driver, RequestContext $context): array
    {
        CanonicalName::parse($name, [$s->tld]); $days = self::rules($s);
        $r = $driver->availability(new Query($name, Action::Transfer, 1), $context);
        $driver->binding()->requireMatch($r->binding);
        $rdap = $this->rdap->precheck($name, $days[0], $days[1], $context->now(), $context);
        if ($context->now() >= $context->deadline) { throw new \DomainException('Transfer check expired.'); }
        if (!$rdap['allowed'] && $rdap['code'] === 'transfer_not_registered') { throw new WriteHeld('transfer_not_registered'); }
        if (!$rdap['allowed']) { throw new TransferRestriction($rdap['code'], isset($rdap['retry_at']) ? new \DateTimeImmutable($rdap['retry_at']) : null, $days, $rdap['registrar_locked'] ?? false); }
        // A public restriction can explain a refusal, but never replaces positive registrar eligibility.
        WriteMoney::requireRead($driver->binding(), $r); WriteMoney::requireAvailable($r->evidence());
        return ['rules' => $days, 'rdap_unchecked' => $rdap['warning'] !== null, 'base_expiry' => $rdap['expires_at'], 'comparison_allowed' => ($rdap['comparison_allowed'] ?? false) && $rdap['allowed']];
    }
}
