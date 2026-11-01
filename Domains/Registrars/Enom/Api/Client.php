<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestContext};

/** Bound read plumbing only. AccountBindings/RegistrarRegistry remain the credential authority. */
final class Client
{
    private static ?\WeakMap $secrets = null;
    /** @internal Construct through the accepted account reader factory. */
    public function __construct(#[\SensitiveParameter] public readonly AccountBinding $binding, #[\SensitiveParameter] string $uid,
        #[\SensitiveParameter] string $token, #[\SensitiveParameter] Transport $transport = new CurlTransport)
    {
        if ($binding->registrarKey !== 'enom' || $token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            throw new \InvalidArgumentException('Invalid Enom credentials.');
        }
        $binding->requireMatch(new AccountBinding('enom', $binding->serverId, $binding->environment, $uid));
        $secrets = self::$secrets ??= new \WeakMap; $secrets[$this] = [$uid, $token, $transport];
    }
    public function read(ReadCommand $command, #[\SensitiveParameter] array $parameters, #[\SensitiveParameter] RequestContext $context): ProtocolResult
    {
        $command->validate($parameters);
        $result=$context->withinRead($this->binding, function (int $remaining) use ($command, $parameters): ProtocolResult {
        try {
            [$uid, $token, $transport] = self::$secrets[$this];
            $body = $transport->send($this->binding->environment, $command, $parameters, $uid, $token, min(25000, $remaining));
            return (new ResponseParser)->parse($command, $body);
        } catch (\Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit $error) { throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit($error->reason);
        } catch (\Throwable) { return new ProtocolResult('unknown', 'transport'); }
        });
        $context->requireReadSuccess($result->status==='success'); return $result;
    }
    public function __debugInfo(): array { return ['registrar' => 'enom', 'environment' => $this->binding->environment->value]; }
    public function __serialize(): array { throw new \LogicException('Registrar clients cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Registrar clients cannot be unserialized.'); }
    public function __clone(): void { throw new \LogicException('Registrar clients cannot be cloned.'); }
}
