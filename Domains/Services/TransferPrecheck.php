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
    public static function rules(SaleSelection $s, bool $locked = false): array
    {
        $tld = Tld::where(['server_id' => $s->serverId, 'tld' => $s->tld])->when($locked, static fn ($q) => $q->lockForUpdate())->firstOrFail();
        $settings = Server::findOrFail($s->serverId)->settings()->whereIn('key',
            ['domains_transfer_endings', 'domains_transfer_creation_lock_days', 'domains_transfer_previous_lock_days'])->when($locked, static fn ($q) => $q->lockForUpdate())->get()->keyBy('key');
        $endings = $settings->get('domains_transfer_endings')?->value;
        // A saved list narrows transfers. Without one, What you sell decides.
        $open = is_string($endings) ? array_map('trim', explode(',', $endings)) : EnomTestTransfer::endings($s->serverId);
        if (!in_array($s->registrar, ['enom', 'namesilo'], true) || $s->action !== 'transfer' || $s->term !== 1
            || !in_array($s->tld, $open, true)
            || in_array(substr($s->tld, (int) strrpos('.'.$s->tld, '.')), ['uk', 'ca', 'eu', 'ai', 'de'], true)
            || !RegistryInputs::supported($s->registrar, $s->tld, $tld->registry_fields)) { throw new \DomainException('Transfer ending is not accepted.'); }
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
        WriteMoney::requireRead($driver->binding(), $r); WriteMoney::requireAvailable($r->evidence());
        $rdap = $this->rdap->precheck($name, $days[0], $days[1], $context->now(), $context);
        if (!$rdap['allowed'] && $rdap['code'] === 'transfer_not_registered') { throw new WriteHeld('transfer_not_registered'); }
        if (!$rdap['allowed'] || $context->now() >= $context->deadline) { throw new \DomainException($rdap['code'] ?? 'Transfer check expired.'); }
        return ['rules' => $days, 'rdap_unchecked' => $rdap['warning'] !== null, 'base_expiry' => $rdap['expires_at'], 'comparison_allowed' => ($rdap['comparison_allowed'] ?? false) && $rdap['allowed']];
    }
}
