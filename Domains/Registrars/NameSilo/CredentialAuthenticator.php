<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{CurlTransport, DeadlineTransport, Environment as WireEnvironment,
    Outcome, RegistrarClient, RegistrarRequest, RequestContext};
/** One bounded balance read authenticates the key; it never attests account identity. */
final readonly class CredentialAuthenticator implements \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator
{
    public function __construct(private Environment $environment, private DeadlineTransport $transport = new CurlTransport) {}
    public function authenticate(Environment $environment, #[\SensitiveParameter] array $credentials): void
    {
        if ($environment !== $this->environment) { throw new \DomainException('Credential environment changed.'); }
        try {
            $client = new RegistrarClient($credentials['api_key'], $environment === Environment::Live ? WireEnvironment::Live : WireEnvironment::Sandbox, $this->transport);
            $result = $client->execute(RegistrarRequest::balance(), RequestContext::Interactive, 10000);
            if ($result->outcome !== Outcome::Success) { throw new \DomainException; }
        } catch (\Throwable) { throw new \DomainException('Registrar key authentication failed.'); }
    }
}
