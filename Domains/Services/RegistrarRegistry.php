<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, RegistrarDriver};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};

final class RegistrarRegistry
{
    private array $entries = [];

    public function register(string $key, array $credentialKeys, AccountVerifier $verifier, \Closure $readerFactory, ?\Closure $balanceFactory = null, bool $accountReadsOnly = false,
        ?\Closure $registrationPreflightFactory = null): void
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
        $this->entries[$key] = [array_keys($keys), $verifier, $readerFactory, $balanceFactory, $accountReadsOnly, $registrationPreflightFactory];
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
            if (!is_string($value) || trim($value) === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
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
        if($key==='namesilo'&&str_starts_with($identity,NameSiloSandbox::PREFIX)){throw new IdentityRefused('namesilo_identity_unavailable');}
        return [new AccountBinding($key, $serverId, $environment, $identity), $identity];
    }

    /** Reader factories cannot expose writer interfaces through this registry. */
    public function reader(AccountBinding $binding, #[\SensitiveParameter] array $credentials, bool $balanceOnly = false): RegistrarDriver
    {
        try { $entry = $this->entry($binding->registrarKey);
            $driver = ($balanceOnly ? ($entry[3] ?? $entry[2]) : $entry[2])($binding, $this->credentials($binding->registrarKey, $credentials)); }
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

    private function entry(string $key): array
    {
        if ($key === 'namesilo' && !isset($this->entries[$key])) { throw new IdentityRefused('namesilo_identity_unavailable'); }
        return $this->entries[$key] ?? throw new \InvalidArgumentException('Registrar is not configured.');
    }
}
