<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RenewalPrice};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\TestCatalogueFacts;
use Paymenter\Extensions\Servers\Domains\Services\RegistryInputs;

/** Authorized provider facts and holder evidence. Public intent never grants execution support.
 * Locked consumers pass their retained rows; this view acquires no locks after operation locks. */
final class Capabilities
{
    public static function routed(): ?self { return CallerRouting::resolve(self::class); }
    public function __construct(private readonly CallerPorts $ports, private readonly Clock $clock, private readonly \Closure $authorize) {}

    public function facts(AccountBinding $b, string $name, ?Tld $locked = null): Facts
    {
        ($this->authorize)(new Query($b, [$name], 'renew'), Context::Holder);
        if ($locked && DB::transactionLevel() === 0) { throw new \LogicException('Holding facts require a transaction.'); }
        $t = $locked ?? Tld::where('server_id', $b->serverId)->where('tld', $name)->sole();
        $this->identity($b, $name, $t);
        $f = OfferState::facts($t->catalogue_facts);
        if (!$f || !$f->fresh($this->clock->now())
            || ($b->registrarKey === 'namesilo' && $b->environment === Environment::Test
                && !TestCatalogueFacts::current($b, $name, $f, $this->clock))) { throw new \DomainException('Current provider facts unavailable.'); }
        return $f;
    }
    public function requireRenewal(AccountBinding $b, string $name, ?Tld $locked = null): Facts
    {
        $f = $this->facts($b, $name, $locked);
        if ($f->renewalSafe !== true || !in_array(1, $f->terms['renew'] ?? [], true)
            || $f->autoRenewOnly !== false) { throw new \DomainException('Renewal capability unavailable.'); }
        return $f;
    }
    public function requireOrder(AccountBinding $b, string $name, string $action, int $years, ?Tld $locked = null): array
    {
        $f = $this->requireRenewal($b, $name, $locked);
        if (!in_array($years, $f->terms[$action] ?? [], true) || $f->requiresNameservers !== false
            || !RegistryInputs::supported($b->registrarKey, $name, $f->registryFields)) { throw new \DomainException('Order capability unavailable.'); }
        return $f->terms[$action];
    }
    /** Bounded local scan, including unsold holdings. No acceptance-ending fallback or provider factory. */
    public function endings(AccountBinding $b, ?string $action = null): array
    {
        $out = [];
        Tld::where('server_id', $b->serverId)->where('account_fingerprint', $b->fingerprint)->where('catalogue_converted', true)
            ->orderBy('id')->chunkById(100, function ($rows) use ($b, $action, &$out): void {
                foreach ($rows as $t) {
                    try { $f = $this->facts($b, $t->tld); if ($action === null || ($f->terms[$action] ?? []) !== []) { $out[] = $t->tld; } }
                    catch (\DomainException|\InvalidArgumentException) { /* Unknown support stays unavailable. */ }
                }
            });
        sort($out, SORT_STRING); return $out;
    }
    /** Enom's read parser needs retained identities even when spending support is unknown.
     * This list grants no action, term or send authority and never copies accepted capabilities. */
    public function identities(AccountBinding $b): array
    {
        $out=[];
        Tld::where('server_id',$b->serverId)->where('account_fingerprint',$b->fingerprint)->where('catalogue_converted',true)
            ->orderBy('id')->chunkById(100,function($rows)use($b,&$out):void {
                ($this->authorize)(new Query($b,$rows->pluck('tld')->all(),'renew'),Context::Holder);
                foreach($rows as $t){$this->identity($b,$t->tld,$t);$out[]=$t->tld;}
            });
        sort($out,SORT_STRING);return $out;
    }
    public function holder(AccountBinding $b, string $name, ?Tld $locked = null): RenewalPrice
    {
        if ($locked) {
            if (DB::transactionLevel() !== 1) { throw new \LogicException('Locked holding TLD requires its outer transaction.'); }
            $this->identity($b, $name, $locked);
            return $this->holding($b, $locked, [])['retail'] ?? throw new \DomainException('No retained annual retail.');
        }
        ($this->authorize)(new Query($b, [$name], 'renew'), Context::Holder); return $this->ports->loadBatch([new Query($b, [$name], 'renew')], Context::Holder)[0][0]->renewal(); }
    public function cost(AccountBinding $b, string $name): ?Cost
    {
        ($this->authorize)(new Query($b, [$name], 'renew'), Context::Holder);
        $row = $this->ports->loadBatch([new Query($b, [$name], 'renew')], Context::Holder)[0][0];
        return $this->current($row->observation());
    }
    /** Forecast only; normalized annual observations remain estimates, never dispatch grants. */
    public function costs(AccountBinding $b): array
    {
        $out=[];
        Tld::where('server_id',$b->serverId)->where('account_fingerprint',$b->fingerprint)->where('catalogue_converted',true)
            ->orderBy('id')->chunkById(100,function($rows)use($b,&$out):void {
                $q=new Query($b,$rows->pluck('tld')->all(),'renew'); ($this->authorize)($q,Context::Holder);
                foreach($this->ports->loadBatch([$q],Context::Holder)[0] as $row){$out[$row->decision->tld]=$this->current($row->observation());}
            });
        return $out;
    }
    /** Already locked holding/adoption snapshot. No policy/account/TLD query is allowed here. */
    public function holding(AccountBinding $b, Tld $t, iterable $prices): array
    {
        ($this->authorize)(new Query($b, [$t->tld], 'renew'), Context::Holder);
        $this->identity($b, $t->tld, $t); $row = null; $cost = null;
        foreach ($prices as $p) {
            if ((int)$p->server_id !== $b->serverId || $p->account_fingerprint !== $b->fingerprint
                || $p->registrar_key !== $b->registrarKey || $p->tld !== $t->tld) { throw new \DomainException('Holding cost binding changed.'); }
            if ($p->action !== 'renew' || (int)$p->term !== 1 || $p->currency !== 'USD') { continue; }
            if ($row !== null) { throw new \DomainException('Duplicate holding cost.'); }
            $row = $p;
            if ($p->catalogue_observation !== null) { $cost = $this->current(OfferState::cost(OfferState::decode($p->catalogue_observation))); }
        }
        $value = OfferState::money($t->catalogue_retained_annual_retail, 2);
        $retail = $value !== null && \Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($value) > 0
            ? new RenewalPrice($row?->id, $row?->revision, $value, 'USD', true, 'retained_renewal') : null;
        return compact('retail', 'cost');
    }
    private function current(?Cost $cost): ?Cost
    { return $cost && $cost->action === 'renew' && $cost->term === 1 && $cost->enabled && $cost->fresh($this->clock->now()) ? $cost : null; }
    private function identity(AccountBinding $b, string $name, Tld $t): void
    {
        if (!$t->catalogue_converted || (int)$t->server_id !== $b->serverId || $t->tld !== $name
            || $t->account_fingerprint !== $b->fingerprint || $t->registrar_key !== $b->registrarKey) { throw new \DomainException('Holding offer binding changed.'); }
    }
}
