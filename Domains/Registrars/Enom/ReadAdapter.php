<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestContext};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, CurlTransport, Environment as WireEnvironment, ProtocolResult, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Services\{EnomLiveCalls, EnomTestCallLimit, EnomTestCalls, EnomTestSendLimit, ProviderSendGuard};

/** AccountBindings and RegistrarRegistry remain the credential authority. */
final class ReadAdapter
{
    private readonly Client $client;

    public function __construct(#[\SensitiveParameter] public readonly AccountBinding $binding, #[\SensitiveParameter] string $uid,
        #[\SensitiveParameter] string $token, #[\SensitiveParameter] ?Transport $transport = null)
    {
        if ($binding->registrarKey !== 'enom' || $token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            throw new \InvalidArgumentException('Invalid Enom credentials.');
        }
        $binding->requireMatch(new AccountBinding('enom', $binding->serverId, $binding->environment, $uid));
        $this->client = new Client(WireEnvironment::from($binding->environment->value), $uid, $token, $transport ?? self::transport());
    }

    public function read(ReadCommand $command, #[\SensitiveParameter] array $parameters, #[\SensitiveParameter] RequestContext $context): ProtocolResult
    {
        $command->validate($parameters);
        $result = $context->withinRead($this->binding, function (int $remaining) use ($command, $parameters, $context): ProtocolResult {
            try {
                return $this->client->read($command, $parameters, min(Client::MAX_TIMEOUT_MS, $remaining));
            } catch (EnomTestCallLimit $refusal) {
                throw new EnomTestCallLimit($refusal->reason);
            } catch (\Throwable) {
                $context->exchangeFailed();
                return new ProtocolResult('unknown', 'transport');
            }
        });
        $context->requireReadSuccess($result->status === 'success',
            $result->code === 'domain_not_in_account' ? new \RuntimeException('Domain is not in registrar account.') : null);
        return $result;
    }

    /** cURL calculates the remaining timeout after preflight and checks the reservation day just before sending. */
    public static function transport(): Transport
    {
        return new CurlTransport(static function (WireEnvironment $environment, ReadCommand $command, #[\SensitiveParameter] string $uid): \Closure {
            try {
                $day = $environment === WireEnvironment::Live ? EnomLiveCalls::read($uid) : '';
                ProviderSendGuard::beforeSend('enom', $environment->value, $command->value);
                EnomTestSendLimit::admit(Environment::from($environment->value), $uid, $command->value);
            } catch (EnomTestCallLimit $refusal) {
                throw new EnomTestCallLimit($refusal->reason);
            }
            return static function () use ($day): void { EnomTestCalls::assertReservedDay($day); };
        });
    }

    public function __debugInfo(): array { return ['registrar' => 'enom', 'environment' => $this->binding->environment->value]; }
    public function __serialize(): array { throw new \LogicException('Registrar clients cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Registrar clients cannot be unserialized.'); }
    public function __clone(): void { throw new \LogicException('Registrar clients cannot be cloned.'); }
}
