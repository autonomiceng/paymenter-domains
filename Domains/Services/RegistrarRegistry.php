<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, AdoptionSource, Clock, CredentialAuthenticator, RegistrarDriver};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};

final class RegistrarRegistry
{
    private array $entries = [];
    private array $boundReaders = [];

    public function register(string $key, array $credentialKeys, AccountVerifier $verifier, \Closure $readerFactory, ?\Closure $balanceFactory = null, bool $accountReadsOnly = false,
        ?\Closure $registrationPreflightFactory = null, ?\Closure $adoptionReaderFactory = null, ?CredentialAuthenticator $authenticator = null): void
    {
        if (!in_array($key, ['enom', 'namesilo'], true) || isset($this->entries[$key]) || $credentialKeys === []) {
            throw new \InvalidArgumentException('Unsupported or duplicate registrar.');
        }
        $keys = [];
        foreach ($credentialKeys as $name) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $name) || isset($keys[$name])) {
                throw new \InvalidArgumentException('Invalid registrar credential fields.');
            }
            $keys[$name] = true;
        }
        $this->entries[$key] = [array_keys($keys), $verifier, $readerFactory, $balanceFactory, $accountReadsOnly, $registrationPreflightFactory, $adoptionReaderFactory, $authenticator];
    }

    /** Deployment-owned read override for one exact account; refusal never falls back to another transport. */
    public function registerBoundReader(AccountBinding $binding, \Closure $factory): void
    {
        $this->entry($binding->registrarKey);
        if (isset($this->boundReaders[$binding->fingerprint])) { throw new \DomainException('Runtime reader already registered.'); }
        $this->boundReaders[$binding->fingerprint] = $factory;
    }

    public function localEnrollment(string $key): bool { return $this->entry($key)[7] !== null; }

    public function authenticate(string $key, Environment $environment, #[\SensitiveParameter] array $credentials): void
    {
        $authenticator = $this->entry($key)[7] ?? throw new \DomainException('Local enrollment unavailable.');
        try { $authenticator->authenticate($environment, $this->credentials($key, $credentials)); }
        catch (\Throwable $error) {
            $safe = $error instanceof \DomainException && in_array($error->getMessage(),[CredentialAuthenticator::UNANSWERED,CredentialAuthenticator::UNCONFIRMED,CredentialAuthenticator::REFUSED],true);
            throw new \DomainException($safe ? $error->getMessage() : 'Registrar key authentication failed.');
        }
    }

    public function keys(): array { return array_keys($this->entries); }

    /** Direct domain-reader factories must honor identity-only deployment composition too. */
    public function requireDomainReads(string $key): void
    { if ($this->entry($key)[4]) { throw new \DomainException('Domain reads require separate deployment composition.'); } }

    public function credentialKeys(string $key): array { return $this->entry($key)[0]; }

    public function credentials(string $key, #[\SensitiveParameter] array $values): array
    {
        $keys = $this->credentialKeys($key); $clean = [];
        if (count($values) !== count($keys)) { throw new \InvalidArgumentException('Registrar credentials incomplete.'); }
        foreach ($keys as $name) {
            $value = $values[$name] ?? null;
            if (!is_string($value) || trim($value) === '' || strlen($value) > 4096 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Registrar credentials invalid.');
            }
            $clean[$name] = $value;
        }
        return $clean;
    }

    public function verify(string $key, int $serverId, Environment $environment, #[\SensitiveParameter] array $credentials): array
    {
        $credentials = $this->credentials($key, $credentials);
        $refusals=EnomTestCalls::refusalGeneration();
        try { $identity = AccountBinding::canonicalIdentity($key, $this->entry($key)[1]->verify($environment, $credentials)); }
        catch (IdentityRefused $error) {
            if(EnomTestCalls::refusedSince($refusals)){throw new EnomTestCallLimit(EnomTestCalls::refusalReason());}
            throw new IdentityRefused($error->reason);
        }
        catch (\Throwable) { throw new \RuntimeException('Registrar account ownership could not be verified.'); }
        if(str_starts_with($identity,LocalAccountEnrollment::PREFIX)||($key==='namesilo'&&str_starts_with($identity,NameSiloSandbox::PREFIX))){throw new IdentityRefused('namesilo_identity_unavailable');}
        return [new AccountBinding($key, $serverId, $environment, $identity), $identity];
    }

    /** Reader factories cannot expose writer interfaces through this registry. */
    public function reader(AccountBinding $binding, #[\SensitiveParameter] array $credentials, bool $balanceOnly = false): RegistrarDriver
    {
        try { $entry = $this->entry($binding->registrarKey);
            $factory = $this->boundReaders[$binding->fingerprint] ?? ($balanceOnly ? ($entry[3] ?? $entry[2]) : $entry[2]);
            $driver = $factory($binding, $this->credentials($binding->registrarKey, $credentials)); }
        catch (IdentityRefused $error) { throw new IdentityRefused($error->reason); }
        catch (\Throwable) { throw new \RuntimeException('Registrar reader could not be created.'); }
        if (!$driver instanceof RegistrarDriver || $driver instanceof \Paymenter\Extensions\Servers\Domains\Contracts\AdmittedRegistrarDriver) {
            throw new \RuntimeException('Registrar reader factory returned an invalid handle.');
        }
        $binding->requireMatch($driver->binding());
        return $driver;
    }

    /** Targeted paid-registration preflight; never used by catalogue or background polling. */
    public function registrationPreflightReader(AccountBinding $binding, #[\SensitiveParameter] array $credentials): RegistrarDriver
    {
        try {
            $factory=$this->entry($binding->registrarKey)[5]??throw new \DomainException('Registration preflight reader is unavailable.');
            $driver=$factory($binding,$this->credentials($binding->registrarKey,$credentials));
        } catch (IdentityRefused $error) { throw new IdentityRefused($error->reason); }
        catch (\Throwable) { throw new \RuntimeException('Registrar preflight reader could not be created.'); }
        if(!$driver instanceof RegistrarDriver||$driver instanceof \Paymenter\Extensions\Servers\Domains\Contracts\AdmittedRegistrarDriver){
            throw new \RuntimeException('Registrar preflight factory returned an invalid handle.');
        }
        $binding->requireMatch($driver->binding());return $driver;
    }

    /** Local composition check only: never construct a reader or invoke a provider. */
    public function requireAdoptionSource(string $key): void
    {
        $this->requireDomainReads($key);
        if ($this->entry($key)[6] === null) { throw new \DomainException('Import reader is unavailable.'); }
    }

    /** Named import handle, independent of the general domain reader and spending ports. */
    public function adoptionReader(AccountBinding $binding, #[\SensitiveParameter] array $credentials, Clock $clock): AdoptionSource
    {
        $this->requireAdoptionSource($binding->registrarKey);
        $factory = $this->entry($binding->registrarKey)[6];
        $reader = $factory($binding, $this->credentials($binding->registrarKey, $credentials), $clock);
        if (!$reader instanceof AdoptionSource || $reader instanceof \Paymenter\Extensions\Servers\Domains\Contracts\AdmittedRegistrarDriver) {
            throw new \DomainException('Invalid import reader.');
        }
        $binding->requireMatch($reader->binding());
        return $reader;
    }

    private function entry(string $key): array
    {
        if ($key === 'namesilo' && !isset($this->entries[$key])) { throw new IdentityRefused('namesilo_identity_unavailable'); }
        return $this->entries[$key] ?? throw new \InvalidArgumentException('Registrar is not configured.');
    }
}
