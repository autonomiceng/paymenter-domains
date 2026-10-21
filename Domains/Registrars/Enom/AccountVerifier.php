<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier as Verifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{CurlTransport, ReadCommand, ResponseParser, Transport};
use Paymenter\Extensions\Servers\Domains\Services\IdentityRefused;
/** GetBalance authentication confirmed against the Enom reseller test environment, 2026-10.
 * Success does not echo UID: proof is authentication of this exact submitted UID/token pair.
 */
final class AccountVerifier implements Verifier
{
    public function __construct(private readonly Environment $boundEnvironment,
        #[\SensitiveParameter] private readonly Transport $transport = new CurlTransport) {}
    public function verify(Environment $environment, #[\SensitiveParameter] array $credentials): string
    {
        if ($environment !== $this->boundEnvironment) { throw new IdentityRefused('identity_environment_refused'); }
        try {
            if (array_diff(array_keys($credentials), ['uid', 'token']) || count($credentials) !== 2
                || !is_string($credentials['uid'] ?? null) || !is_string($credentials['token'] ?? null)) { throw new \InvalidArgumentException; }
            $uid = AccountBinding::canonicalIdentity('enom', $credentials['uid']);
            $token = $credentials['token'];
            if (trim($token) === '' || strlen($token) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $token)) { throw new \InvalidArgumentException; }
            // Exactly one named account-only read, ten seconds total, no fallback/retry.
            $reply = (new ResponseParser)->parse(ReadCommand::Balance,
                $this->transport->send($this->boundEnvironment, ReadCommand::Balance, [], $credentials['uid'], $token, 10000));
        } catch (\Throwable) { throw new IdentityRefused('identity_unavailable'); }
        if ($reply->status === 'rejected' && $reply->code === 'identity_credentials_refused') { throw new IdentityRefused('identity_credentials_refused'); }
        if ($reply->status !== 'success' || $reply->value('errcount') !== '0'
            || strtolower((string)$reply->value('done')) !== 'true' || Balance::read($reply) === null) { throw new IdentityRefused('identity_unavailable'); }
        return $uid;
    }
    public function __debugInfo(): array { return ['environment' => $this->boundEnvironment->value]; }
    public function __serialize(): array { throw new \LogicException('Identity verifiers cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Identity verifiers cannot be unserialized.'); }
    public function __clone(): void { throw new \LogicException('Identity verifiers cannot be cloned.'); }
}
