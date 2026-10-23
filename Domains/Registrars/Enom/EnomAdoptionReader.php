<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, ExpiryPrecision, Query, RequestBudget, RequestContext, RequestOrigin, SystemClock};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{AdoptionReadCommand as Command, AdoptionTransport, Client, Transport};

/** One name, one bounded readback, no general driver or caller-supplied context. */
final class EnomAdoptionReader
{
    private readonly Client $client;
    public function __construct(public readonly AccountBinding $binding, #[\SensitiveParameter] string $uid,
        #[\SensitiveParameter] string $token, #[\SensitiveParameter] Transport $wire,
        private readonly Clock $clock = new SystemClock, private readonly ?ReadLimiter $limiter = null)
    { $this->client = new Client($binding, $uid, $token, new AdoptionTransport($wire)); }

    public function read(string $name, string $suffix): AdoptionReadback
    {
        Query::requireName($name); $nameParts = CanonicalName::parse($name, [$suffix]);
        $parts = ['SLD' => $nameParts['sld'], 'TLD' => $nameParts['tld']];
        $start = $this->clock->now();
        $context = new RequestContext($this->binding, RequestOrigin::Interactive, $start->modify('+90 seconds'), new RequestBudget(6), $this->clock, $this->limiter);
        $failure = fn (string $code) => AdoptionReadback::refused($this->binding, $code);
        try {
            $info = $this->client->read(Command::Info->protocol(), $parts, $context);
            if ($info->status !== 'success') { return $failure('read_failed'); }
            $identity = Identity::read($this->binding, $name, $parts, $info);
            if ($identity?->owned !== true || $identity->providerReference === null) { return $failure('identity_unconfirmed'); }
            $exp = $this->client->read(Command::Expiry->protocol(), $parts, $context);
            $renew = $this->client->read(Command::Renewal->protocol(), $parts, $context);
            $extend = $this->client->read(Command::RenewalInfo->protocol(), $parts, $context);
            foreach ([$info, $exp, $renew, $extend] as $reply) {
                if ($reply->status !== 'success') { return $failure('read_failed'); }
            }
            $auto = Driver::autoRenew($renew);
            if ($auto !== false) { return $failure($auto === true ? 'auto_renew_on' : 'auto_renew_unknown'); }
            $expiry = $identity->expiry;
            if ($expiry === null || $expiry->precision !== ExpiryPrecision::Instant) { return $failure('expiry_unconfirmed'); }
            $dedicated = Expiry::read($exp);
            if ($dedicated === null || $dedicated->precision !== $expiry->precision || $dedicated->value !== $expiry->value
                || Expiry::reconcile($extend, $expiry, $info) === null) { return $failure('expiry_disagrees'); }
            if (new \DateTimeImmutable($expiry->value) <= $this->clock->now()) { return $failure('expired'); }
            if (new \DateTimeImmutable($expiry->value) > $start->modify('+10 years')) { return $failure('state_unconfirmed'); }
            $limits = Renewal::limits($extend);
            if ($limits === null || $limits['hold'] !== false) { return $failure('state_unconfirmed'); }
            foreach (['getdomaininfo.status.redemption', 'getdomaininfo.status.onhold', 'getextendinfo.redemption'] as $path) {
                $flag = ($path === 'getextendinfo.redemption' ? $extend : $info)->value($path);
                if ($flag !== null && Driver::flag($flag) !== false) { return $failure('state_unconfirmed'); }
            }
            if ($this->clock->now() >= $context->deadline) { return $failure('read_failed'); }
            return AdoptionReadback::verified($this->binding, [ 'name' => $name,
                'provider_id' => $identity->providerReference, 'expiry' => $expiry, 'auto_renew' => false,
                'registrar_hold' => false, 'read_start' => $start->format(DATE_ATOM), 'read_end' => $this->clock->now()->format(DATE_ATOM), 'command_set_version' => 1]);
        } catch (\Throwable) { return $failure('read_failed'); }
    }
    public function __debugInfo(): array { return ['environment' => $this->binding->environment->value]; }
    public function __serialize(): array { throw new \LogicException('Reader cannot be serialized.'); }
}
