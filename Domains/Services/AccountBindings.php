<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;

// Binding policy adapted from Enom d2c55e2 Services/RuntimeGuard.php.
final class AccountBindings
{
    private const CREDENTIALS = 'domains_credentials';
    private const VERIFIED = 'domains_verified_credentials';

    public function __construct(private readonly RegistrarRegistry $registry) {}

    /** First save and rotations both require positive provider identity; verification never holds locks. */
    public function configure(int $serverId, string $registrar, Environment $environment, #[\SensitiveParameter] array $credentials, ?StaffSettingsReview $review = null): AccountBinding
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Account verification must run outside database locks.'); }
        if ($review) {
            if ($review->action !== 'configure' || $review->serverId !== $serverId) { throw new \DomainException('Configuration review mismatch.'); }
            $review->local(fn () => null, 'verification_requested'); // Consume before injected I/O.
        }
        $phase = 'verification_refused';
        try {
            $credentials = $this->registry->credentials($registrar, $credentials);
            [$binding, $identity] = $this->registry->verify($registrar, $serverId, $environment, $credentials);
            try {
                $saved = DB::transaction(function () use ($serverId, $binding, $identity, $credentials, $review): AccountBinding {
                    $review?->lockActor(); $review?->lockAccounts(); $review?->check(true);
                    $this->server($serverId, true);
                    $account = Account::where('server_id', $serverId)->lockForUpdate()->first();
                    if ($account) { $binding->requireMatch($this->binding($account)); }
                    else {
                        $account = Account::withVerifiedBindingWrite(static fn () => Account::create(['server_id' => $serverId, 'registrar_key' => $binding->registrarKey,
                            'environment' => $binding->environment->value, 'stable_identity' => $identity,
                            'account_fingerprint' => $binding->fingerprint]));
                    }
                    // Native Setting encrypts in its saving event: mark encryption BEFORE assigning the first value.
                    $this->saveSecret($serverId, self::CREDENTIALS, json_encode($credentials, JSON_THROW_ON_ERROR));
                    $this->saveSecret($serverId, self::VERIFIED, $this->digest($binding, $credentials));
                    // Rotation pauses processing until deliberately resumed, preserving binding and live site.
                    $account->processing_enabled = false; Account::withVerifiedBindingWrite(static fn () => $account->save());
                    $review?->record('verified_and_saved');
                    return $binding;
                });
            } catch (\Throwable) {
                // The native transaction callback captures plaintext: never retain its exception trace.
                throw new \DomainException('Registrar account configuration could not be saved.');
            }
            $phase = 'verification_finished';
            return $saved;
        } finally { $review?->finish($phase); }
    }

    public function confirmLiveSite(int $serverId, string $explicitSite): void
    {
        $site = self::site();
        if ($explicitSite !== $site) { throw new \InvalidArgumentException('Explicit current live site required.'); }
        DB::transaction(function () use ($serverId, $site): void {
            $this->server($serverId, true);
            $account = Account::where('server_id', $serverId)->lockForUpdate()->firstOrFail();
            if ($account->environment !== Environment::Live->value || ($account->live_site !== null && $account->live_site !== $site)) {
                throw new \DomainException('Live site binding is immutable.');
            }
            $this->configuration($account);
            $account->live_site = $site; Account::withVerifiedBindingWrite(static fn () => $account->save());
        });
    }

    public function setProcessing(int $serverId, bool $enabled): void
    {
        DB::transaction(function () use ($serverId, $enabled): void {
            $this->server($serverId, true);
            $account = Account::where('server_id', $serverId)->lockForUpdate()->firstOrFail();
            if ($enabled) { $this->configuration($account, true); }
            $account->processing_enabled = $enabled; Account::withVerifiedBindingWrite(static fn () => $account->save());
        });
    }

    public function reader(int $serverId): RegistrarDriver
    {
        $this->server($serverId);
        $account = Account::where('server_id', $serverId)->firstOrFail();
        return $this->registry->reader($this->binding($account), $this->configuration($account, true));
    }

    /** Guard for later engine claim/dispatch; never grants admission by itself. */
    public function requireProcessing(AccountBinding $expected): void
    {
        $this->server($expected->serverId);
        $account = Account::where('server_id', $expected->serverId)->firstOrFail();
        $expected->requireMatch($this->binding($account));
        $this->configuration($account, true);
        if (!$account->processing_enabled) { throw new \DomainException('Registrar processing is disabled.'); }
    }

    /** Caller holds these server/account rows in its checkout/claim transaction; never re-query a snapshot. */
    public function requireProcessingLocked(Server $server, Account $account): void
    {
        if (DB::transactionLevel() === 0) { throw new \LogicException('Locked processing requires an outer transaction.'); }
        if (!$server->enabled || $server->extension !== 'Domains' || (int) $account->server_id !== (int) $server->id) {
            throw new \DomainException('Locked registrar server is unavailable.');
        }
        $this->binding($account);
        $this->configuration($account, true, $server);
        if (!$account->processing_enabled) { throw new \DomainException('Registrar processing is disabled.'); }
    }

    /** Local sync commit recheck against current locked credentials, independent of spending pause. */
    public function requireReadableLocked(Server $server, Account $account): void
    {
        if (DB::transactionLevel() === 0 || !$server->enabled || $server->extension !== 'Domains'
            || (int) $account->server_id !== (int) $server->id) { throw new \DomainException('Unavailable catalogue account.'); }
        $this->binding($account); $this->configuration($account, true, $server);
    }

    private function server(int $id, bool $lock = false): Server
    {
        $query = Server::whereKey($id); if ($lock) { $query->lockForUpdate(); }
        $server = $query->firstOrFail();
        if ($server->extension !== 'Domains' || !$server->enabled) { throw new \DomainException('Domains server is unavailable.'); }
        return $server;
    }

    private function binding(Account $account): AccountBinding
    {
        $binding = new AccountBinding($account->registrar_key, (int) $account->server_id,
            Environment::from($account->environment), $account->stable_identity);
        if (!hash_equals($binding->fingerprint, $account->account_fingerprint)) { throw new \DomainException('Registrar binding mismatch.'); }
        return $binding;
    }

    private function configuration(Account $account, bool $requireSite = false, ?Server $lockedServer = null): array
    {
        try {
            $binding = $this->binding($account);
            if ($requireSite && $binding->environment === Environment::Live && $account->live_site !== self::site()) {
                throw new \DomainException('Live mode is not bound to this site.');
            }
            $credentials = json_decode($this->secret((int) $account->server_id, self::CREDENTIALS, $lockedServer), true, 8, JSON_THROW_ON_ERROR);
            $credentials = $this->registry->credentials($binding->registrarKey, $credentials);
            if (!hash_equals($this->secret((int) $account->server_id, self::VERIFIED, $lockedServer), $this->digest($binding, $credentials))) {
                throw new \DomainException('Registrar credentials changed without verified rotation.');
            }
            return $credentials;
        } catch (\Throwable) {
            throw new \DomainException('Registrar configuration could not be verified.');
        }
    }

    private function saveSecret(int $serverId, string $key, #[\SensitiveParameter] string $value): void
    {
        $server = $this->server($serverId);
        $setting = $server->settings()->firstOrNew(['key' => $key]);
        $setting->encrypted = true; $setting->type = 'string'; $setting->value = $value; $setting->save();
    }

    private function secret(int $serverId, string $key, ?Server $lockedServer = null): string
    {
        $query = ($lockedServer ?? $this->server($serverId))->settings()->where('key', $key);
        if ($lockedServer) { $query->lockForUpdate(); }
        $setting = $query->first();
        if (!$setting || !$setting->encrypted || !is_string($setting->value) || $setting->value === '') {
            throw new \DomainException('Encrypted registrar settings are required.');
        }
        return $setting->value;
    }

    private function digest(AccountBinding $binding, #[\SensitiveParameter] array $credentials): string
    {
        return hash_hmac('sha256', $binding->fingerprint.json_encode($credentials, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private static function site(): string
    {
        $site = rtrim((string) config('app.url'), '/'); $parts = parse_url($site);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || strlen($site) > 255) {
            throw new \DomainException('A canonical HTTPS application URL is required for live mode.');
        }
        return $site;
    }
}
