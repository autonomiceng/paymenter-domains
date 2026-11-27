<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

final class Client
{
    public const MAX_TIMEOUT_MS = 25000;

    private static ?\WeakMap $secrets = null;

    public function __construct(private readonly Environment $environment, #[\SensitiveParameter] string $uid,
        #[\SensitiveParameter] string $token, #[\SensitiveParameter] Transport $transport)
    {
        $secrets = self::$secrets ??= new \WeakMap;
        $secrets[$this] = [$uid, $token, $transport];
    }

    public function read(ReadCommand $command, #[\SensitiveParameter] array $parameters, int $timeoutMs): ProtocolResult
    {
        $command->validate($parameters);
        if ($timeoutMs < 1 || $timeoutMs > self::MAX_TIMEOUT_MS) {
            throw new \InvalidArgumentException('Invalid read deadline.');
        }
        [$uid, $token, $transport] = self::$secrets[$this];
        return (new ResponseParser)->parse($command, $transport->send($this->environment, $command, $parameters, $uid, $token, $timeoutMs));
    }

    public function __serialize(): array { throw new \LogicException('Registrar clients cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Registrar clients cannot be unserialized.'); }
    public function __clone(): void { throw new \LogicException('Registrar clients cannot be cloned.'); }
}
