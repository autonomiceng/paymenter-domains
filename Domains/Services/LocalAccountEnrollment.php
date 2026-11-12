<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
/** Stable local lineage independent of credentials and provider contacts. */
final class LocalAccountEnrollment
{
    public const KEY = 'domains_local_account_enrollment';
    public const PREFIX = 'local-account-enrollment:';
    public static function enrolled(Account $account): bool
    { return str_starts_with($account->stable_identity, self::PREFIX); }
    public static function bootstrap(RegistrarRegistry $registry, NameSilo\Api\DeadlineTransport $transport = new NameSilo\Api\CurlTransport): bool
    {
        $environment = Environment::tryFrom((string) config('domains.namesilo_account.environment', ''));
        if (!$environment) { return false; }
        if (NameSiloSandbox::enabled()) { throw new \DomainException('Choose one NameSilo enrollment composition.'); }
        $registry->register('namesilo', ['api_key'], new NameSilo\AccountVerifier,
            static function(AccountBinding $binding) use ($environment): NameSilo\Driver {
                if($binding->environment!==$environment){throw new \DomainException('Credential environment changed.');}
                $clock=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class);
                return ($environment===Environment::Test?NameSilo\TestRuntime::reader($binding,$clock):NameSilo\LiveRuntime::reader($binding,$clock))($binding);
            },
            static function (AccountBinding $binding, #[\SensitiveParameter] array $credentials) use ($environment): NameSilo\Driver {
                if ($binding->environment !== $environment) { throw new \DomainException('Credential environment changed.'); }
                if($environment===Environment::Live){
                    return (NameSilo\LiveRuntime::reader($binding,app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)))($binding);
                }
                return new NameSilo\Driver(new NameSilo\Client($binding, $credentials['api_key']), []);
            }, false,
            authenticator: new NameSilo\CredentialAuthenticator($environment, $transport));
        return true;
    }
    /** Encoding failures must never retain a built-in trace containing the submitted key. */
    public static function keyFingerprint(#[\SensitiveParameter] array $credentials): string
    {
        try { return hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR)); }
        catch (\Throwable) { throw new \InvalidArgumentException('Registrar credentials invalid.'); }
    }
    public static function facts(AccountBinding $binding, string $identity, #[\SensitiveParameter] array $credentials): array
    {
        return ['contract' => 'local_account_enrollment_v1', 'binding' => $binding->fingerprint, 'identity' => $identity,
            'provider_confirmed_account' => false, 'credential_revision' => bin2hex(random_bytes(16)),
            'key_fingerprint' => self::keyFingerprint($credentials)];
    }
    public static function validate(Account $account, #[\SensitiveParameter] array $facts, #[\SensitiveParameter] array $credentials): void
    {
        // An authenticated TEST upgrade or rotation retains its original OTE identity and all ledger bindings.
        $retainedTest = $account->registrar_key === 'namesilo' && $account->environment === Environment::Test->value && NameSiloSandbox::enrolled($account);
        if ((!self::enrolled($account) && !$retainedTest) || ($facts['contract'] ?? null) !== 'local_account_enrollment_v1'
            || ($facts['provider_confirmed_account'] ?? null) !== false || ($facts['identity'] ?? null) !== $account->stable_identity
            || ($facts['binding'] ?? null) !== $account->account_fingerprint
            || !is_string($facts['credential_revision'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $facts['credential_revision'])
            || ($facts['key_fingerprint'] ?? null) !== self::keyFingerprint($credentials)) {
            throw new \DomainException('Local account enrollment changed.');
        }
    }
}
