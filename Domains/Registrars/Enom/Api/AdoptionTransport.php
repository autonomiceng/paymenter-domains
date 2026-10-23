<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

use Paymenter\Extensions\Servers\Domains\Data\Environment;

/** Recheck at the wire boundary; a general read command is insufficient authority. */
final class AdoptionTransport implements Transport
{
    public function __construct(#[\SensitiveParameter] private readonly Transport $wire) {}
    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        if (AdoptionReadCommand::tryFrom($command->value) === null) { throw new \DomainException('Adoption command refused.'); }
        $command->validate($parameters);
        if ($timeoutMs < 1 || $timeoutMs > 25000) { throw new \DomainException('Adoption timeout refused.'); }
        return $this->wire->send($environment, $command, $parameters, $uid, $token, $timeoutMs);
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): array { throw new \LogicException('Transport cannot be serialized.'); }
}
