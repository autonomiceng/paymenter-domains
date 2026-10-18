<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
/** Trusted composition only; no default sender or installed factory. Never selects today's seller. */
final readonly class OwnerPorts
{
    public function __construct(private ?\Closure $factory = null) {}
    public function forDomain(Domain $domain): ManagementProtocol
    {
        if (!in_array($domain->registrar_key,['enom','namesilo'],true)) { throw new \DomainException('Unsupported owner setup.'); }
        $account = Account::where('server_id', $domain->server_id)->firstOrFail();
        $binding = CatalogueAcceptances::binding($account);
        if ($binding->fingerprint !== $domain->account_fingerprint || $binding->registrarKey !== $domain->registrar_key) { throw new \DomainException('Holding changed.'); }
        $port = $this->factory ? ($this->factory)($binding) : null;
        if (!$port instanceof ManagementProtocol || !$port->bindsTo($binding) || !$port->capabilities()->binding->matches($binding)) { throw new \DomainException('Management unavailable.'); }
        return $port;
    }
}
