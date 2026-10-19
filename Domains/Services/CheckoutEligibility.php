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
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock, private readonly ?TransferPrecheck $transfers = null) {}

    public function observe(string $name, SaleSelection $selection, array $identity): string
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Eligibility reads require an unlocked cart.'); }
        if (!in_array($selection->action, ['register', 'transfer'], true)) { throw new \DomainException('Acquisition unavailable.'); }
        $driver = $this->bindings->reader($selection->serverId);
        if ($driver->binding()->fingerprint !== $selection->fingerprint) { throw new \DomainException('Eligibility account changed.'); }
        $started = $this->clock->now();
        $transfer = null;
        if ($selection->action === 'transfer') {
            $transfer = ($this->transfers ?? throw new \DomainException('Transfer eligibility is not configured.'))->check($name, $selection, $driver,
                new RequestContext($driver->binding(), RequestOrigin::Interactive, $started->modify('+25 seconds'), new RequestBudget(3), $this->clock, Runtime::limiter()));
        } else {
        $result = $driver->availability(new Query($name, Action::Register, $selection->term),
            new RequestContext($driver->binding(), RequestOrigin::Interactive, $started->modify('+10 seconds'), new RequestBudget(1), $this->clock, Runtime::limiter()));
        $driver->binding()->requireMatch($result->binding);
        if ($result->disposition !== Disposition::Accepted || $result->evidence()->premium !== false || $result->evidence()->available !== true
            || $this->clock->now() >= $started->modify('+10 seconds')) { throw new \DomainException('Domain eligibility could not be confirmed.'); }
        }
        $token = bin2hex(random_bytes(32));
        Cache::put('domains.checkout.'.$token, ['transfer' => $transfer, 'identity' => $identity, 'selection' => get_object_vars($selection),
            'name' => $name, 'observed' => $started->getTimestamp(), 'expires' => $started->modify('+5 minutes')->getTimestamp()], 300);
        return $token;
    }

    public function require(string $token, string $name, array $identity, bool $locked = false): SaleSelection
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \DomainException('Refresh the domain check.'); }
        $receipt = Cache::get('domains.checkout.'.$token);
        $now = $this->clock->now()->getTimestamp();
        if (!is_array($receipt) || $receipt['identity'] !== $identity || $receipt['name'] !== $name
            || $receipt['observed'] > $now || $receipt['expires'] <= $now) { throw new \DomainException('Refresh the domain check.'); }
        $s = new SaleSelection(...$receipt['selection']);
        if ($s->action === 'transfer' && (($receipt['transfer']['rules'] ?? null) !== TransferPrecheck::rules($s, $locked))) { throw new \DomainException('Transfer rules changed.'); }
        return $s;
    }
}
