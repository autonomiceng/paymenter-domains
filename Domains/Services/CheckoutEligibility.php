<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Action, Disposition, Query, RequestBudget, RequestContext, RequestOrigin, SaleSelection};

/** Short-lived server-side read evidence. A receipt never replaces locked seller admission. */
final class CheckoutEligibility
{
    // Reserve time for the remaining batch, pacing waits and native lock acquisition.
    private const REFRESH_HEADROOM_SECONDS = 60;
    private bool $comparisonSeen = false;
    private ?string $comparisonName = null;
    private ?\Closure $comparisonGuard = null;
    /** Same singleton used by native cart listeners; scope cannot leak into later requests. */
    public function comparison(string $name,callable $handoff,callable $guard): mixed
    {
        if ($this->comparisonName!==null) { throw new \LogicException('Comparison handoff already active.'); }
        $this->comparisonName=$name;$this->comparisonGuard=\Closure::fromCallable($guard);
        try { $result=$handoff();if (!$this->comparisonSeen) { throw new \DomainException('Comparison admission is unavailable.'); }return $result; } finally { $this->comparisonName=null;$this->comparisonGuard=null;$this->comparisonSeen=false; }
    }
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock, private readonly ?TransferPrecheck $transfers = null) {}

    /** Registration refreshes share one bounded read per cart/name/account/offer. */
    public function refresh(string $token, string $name, SaleSelection $selection, array $identity, ?CheckoutRefreshBudget $batch = null): string
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Eligibility reads require an unlocked cart.'); }
        $batch?->remainingMilliseconds();
        $receipt = Cache::get('domains.checkout.'.$token);
        if (is_array($receipt) && ($receipt['identity'] !== $identity || $receipt['name'] !== $name)) {
            throw new \DomainException('Domain check does not match this cart.');
        }
        if ($this->reusable($token, $name, $selection, $identity)) { return $token; }
        return $this->observe($name, $selection, $identity, batch: $batch);
    }

    private function reusable(string $token, string $name, SaleSelection $selection, array $identity): bool
    {
        try {
            $previous = $this->require($token, $name, $identity, true);
            $receipt = Cache::get('domains.checkout.'.$token);
            if ($selection->action === 'register' && (!is_array($receipt)
                || $receipt['expires'] <= $this->clock->now()->getTimestamp() + self::REFRESH_HEADROOM_SECONDS)) { return false; }
            $old = get_object_vars($previous); $current = get_object_vars($selection);
            unset($old['priceRevision'], $current['priceRevision']);
            return $old === $current && $previous->priceRevision <= $selection->priceRevision;
        } catch (\DomainException) { return false; }
    }

    public function observe(string $name, SaleSelection $selection, array $identity, bool $comparison = false, ?CheckoutRefreshBudget $batch = null): string
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Eligibility reads require an unlocked cart.'); }
        // Transfer and comparison semantics remain on their existing path.
        if ($selection->action !== 'register' || $comparison || $this->comparisonName !== null) { return $this->observeFresh($name, $selection, $identity, $comparison); }
        $key = 'domains.checkout.refresh.'.hash('sha256', json_encode([$name, $identity, get_object_vars($selection)], JSON_THROW_ON_ERROR));
        $read = function () use ($key, $name, $selection, $identity, $batch): string {
            $batch?->remainingMilliseconds();
            $token = Cache::get($key);
            if (is_string($token) && $this->reusable($token, $name, $selection, $identity)) { return $token; }
            if ($token === false) { throw new \DomainException('Domain eligibility could not be confirmed.'); }
            try { $token = $this->observeFresh($name, $selection, $identity, batch: $batch); }
            catch (\DomainException $e) {
                // Coalesce failed reads briefly too; retain every other guard's typed refusal.
                if ($e::class === \DomainException::class && $e->getMessage() === 'Domain eligibility could not be confirmed.') { Cache::put($key, false, 10); }
                throw $e;
            }
            Cache::put($key, $token, 300);
            return $token;
        };
        try { return Cache::lock($key.'.lock', 30)->block($batch ? min(12, $batch->remainingMilliseconds() / 1000) : 12, $read); }
        catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            if ($batch) { throw new CheckoutRefreshTimeout; }
            throw $e;
        }
    }

    private function observeFresh(string $name, SaleSelection $selection, array $identity, bool $comparison = false, ?CheckoutRefreshBudget $batch = null): string
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Eligibility reads require an unlocked cart.'); }
        if (!in_array($selection->action, ['register', 'transfer'], true)) { throw new \DomainException('Acquisition unavailable.'); }
        $driver = $this->bindings->reader($selection->serverId);
        if ($driver->binding()->fingerprint !== $selection->fingerprint) { throw new \DomainException('Eligibility account changed.'); }
        $price = \Paymenter\Extensions\Servers\Domains\Models\Price::findOrFail($selection->priceId);
        if ($price->revision !== $selection->priceRevision || $price->retail === null) { throw new \DomainException('Refresh the domain offer.'); }
        $retail = PriceCatalogue::retail($price->retail);
        $started = $this->clock->now();
        $transfer = null;
        if ($selection->action === 'transfer') {
            $transfer = ($this->transfers ?? throw new \DomainException('Transfer eligibility is not configured.'))->check($name, $selection, $driver,
                new RequestContext($driver->binding(), RequestOrigin::Interactive, $started->modify('+25 seconds'), new RequestBudget(3), $this->clock, Runtime::limiter()));
        } else {
        $deadline = $started->modify('+'.min(10000, $batch?->remainingMilliseconds() ?? 10000).' milliseconds');
        $result = $driver->availability(new Query($name, Action::Register, $selection->term),
            new RequestContext($driver->binding(), RequestOrigin::Interactive, $deadline, new RequestBudget(1), $this->clock, Runtime::limiter()));
        $batch?->remainingMilliseconds();
        if ($batch && $deadline < $started->modify('+10 seconds') && $this->clock->now() >= $deadline) { throw new CheckoutRefreshTimeout; }
        $driver->binding()->requireMatch($result->binding);
        if ($result->disposition !== Disposition::Accepted || $result->evidence()->premium !== false || $result->evidence()->available !== true
            || $this->clock->now() >= $deadline) { throw new \DomainException('Domain eligibility could not be confirmed.'); }
        }
        $comparison = $comparison || $this->comparisonName === $name;
        if ($comparison && (!$transfer || !WatchTransferPolicy::eligible($transfer, $this->clock->now()->getTimestamp()))) {
            throw new \DomainException('Transfer eligibility remains unverified.');
        }
        if ($this->comparisonName === $name) { ($this->comparisonGuard)();$this->comparisonSeen=true; }
        $token = bin2hex(random_bytes(32));
        Cache::put('domains.checkout.'.$token, ['retail' => $retail, 'comparison' => $comparison, 'transfer' => $transfer, 'identity' => $identity, 'selection' => get_object_vars($selection),
            'name' => $name, 'observed' => $started->getTimestamp(), 'expires' => $started->modify('+5 minutes')->getTimestamp()], 300);
        return $token;
    }

    public function require(string $token, string $name, array $identity, bool $locked = false): SaleSelection
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \DomainException('Refresh the domain check.'); }
        $receipt = Cache::get('domains.checkout.'.$token);
        $now = $this->clock->now()->getTimestamp();
        if (!is_array($receipt)) { throw new CheckoutCheckExpired; }
        if ($receipt['identity'] !== $identity || $receipt['name'] !== $name || $receipt['observed'] > $now) { throw new \DomainException('Refresh the domain check.'); }
        if ($receipt['expires'] <= $now) { throw new CheckoutCheckExpired; }
        if (($receipt['comparison'] ?? false) && !WatchTransferPolicy::eligible($receipt['transfer'] ?? [], $now)) { throw new \DomainException('Refresh the comparison.'); }
        $s = new SaleSelection(...$receipt['selection']);
        if ($locked) {
            // Admission already owns this price lock. Revisions may advance at unchanged retail;
            // a changed amount requires a new customer check, even if native relations were reloaded.
            $price = \Paymenter\Extensions\Servers\Domains\Models\Price::find($s->priceId);
            if (!$price || $price->retail === null || ($receipt['retail'] ?? null) !== PriceCatalogue::retail($price->retail)) {
                throw new \DomainException('Refresh the domain offer.');
            }
        }
        if ($s->action === 'transfer' && (($receipt['transfer']['rules'] ?? null) !== TransferPrecheck::rules($s, $locked))) { throw new \DomainException('Transfer rules changed.'); }
        return $s;
    }
}
