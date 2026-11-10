<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{CurlTransport, DeadlineTransport, Environment as WireEnvironment,
    Outcome, RegistrarClient, RegistrarRequest, RejectionScope, RequestContext};
/** One bounded balance read authenticates the key; it never attests account identity. */
final readonly class CredentialAuthenticator implements \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator
{
    /** No key, wrong key, wrong user, sub-account, IP limit and wrong sandbox account. */
    private const KEY_REFUSALS = [109, 110, 111, 112, 113, 116];
    public function __construct(private Environment $environment, private DeadlineTransport $transport = new CurlTransport) {}
    public function authenticate(Environment $environment, #[\SensitiveParameter] array $credentials): void
    {
        if ($environment !== $this->environment) { throw new \DomainException('Credential environment changed.'); }
        try {
            $client = new RegistrarClient($credentials['api_key'], $environment === Environment::Live ? WireEnvironment::Live : WireEnvironment::Sandbox, $this->transport);
            $result = $client->execute(RegistrarRequest::balance(), RequestContext::Interactive, 10000);
        } catch (\Throwable) { throw new \DomainException(self::UNCONFIRMED); }
        // Only a documented key or account refusal says the key is wrong. Card profile, funds, request and other replies stay unconfirmed.
        if ($result->outcome === Outcome::Rejected && $result->scope === RejectionScope::Account && in_array($result->code, self::KEY_REFUSALS, true)) {
            throw new \DomainException(self::REFUSED);
        }
        if ($result->outcome !== Outcome::Success) { throw new \DomainException(self::UNCONFIRMED); }
    }
}
