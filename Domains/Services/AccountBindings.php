<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Support\Facades\{DB,Schema};
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
            $existing=Account::where('server_id',$serverId)->first();
            if($existing&&NameSiloSandbox::enrolled($existing)){throw new \DomainException('Sandbox enrollment is immutable.');}
            $credentials = $this->registry->credentials($registrar, $credentials);
            [$binding, $identity] = $this->registry->verify($registrar, $serverId, $environment, $credentials);
            try {
                $saved = DB::transaction(function () use ($serverId, $binding, $identity, $credentials, $review): AccountBinding {
                    $review?->lockActor(); $review?->lockAccounts(); $review?->check(true);
                    $this->server($serverId, true);
                    $account = Account::where('server_id', $serverId)->lockForUpdate()->first();
                    if ($account) { if (!$binding->matches($this->binding($account))) { throw new IdentityRefused('identity_account_changed'); } }
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
            } catch (IdentityRefused $error) { throw new IdentityRefused($error->reason); } catch (\Throwable) {
                // The native transaction callback captures plaintext: never retain its exception trace.
                throw new \DomainException('Registrar account configuration could not be saved.');
            }
            $phase = 'verification_finished';
            return $saved;
        } finally { $review?->finish($phase); }
    }

    /** Single-use staff review, authenticated OTE key, and a fresh local binding; no identity fallback. */
    public function enrollSandbox(int $serverId, Environment $environment, #[\SensitiveParameter] string $label,
        #[\SensitiveParameter] array $credentials, StaffSettingsReview $review): AccountBinding
    {
        if(DB::transactionLevel()!==0||$review->action!=='enroll_sandbox'||$review->serverId!==$serverId){throw new \DomainException('Sandbox review mismatch.');}
        $review->local(fn()=>null,'sandbox_enrollment_requested');$phase='sandbox_enrollment_refused';
        try {
            NameSiloSandbox::requireTest($environment);
            $facts=NameSiloSandbox::facts($serverId,$label,$credentials);
            $this->freshSandbox($serverId);$this->uniqueSandboxKey($facts);
            NameSiloSandbox::checkKey($credentials);
            try {
                $saved=DB::transaction(function()use($serverId,$environment,$credentials,$facts,$review):AccountBinding {
                    $review->lockActor();$review->lockAccounts();$review->check(true);
                    NameSiloSandbox::requireTest($environment);$this->freshSandbox($serverId,true);$this->uniqueSandboxKey($facts,true);
                    $identity=NameSiloSandbox::identity($facts);$binding=new AccountBinding('namesilo',$serverId,$environment,$identity);
                    Account::withVerifiedBindingWrite(static fn()=>Account::create(['server_id'=>$serverId,'registrar_key'=>'namesilo',
                        'environment'=>$environment->value,'stable_identity'=>$identity,'processing_enabled'=>false]));
                    $this->saveSecret($serverId,NameSiloSandbox::KEY,json_encode($facts,JSON_THROW_ON_ERROR));
                    $this->saveSecret($serverId,self::CREDENTIALS,json_encode($credentials,JSON_THROW_ON_ERROR));
                    $this->saveSecret($serverId,self::VERIFIED,$this->digest($binding,$credentials));
                    $review->record('sandbox_enrollment_saved');return $binding;
                });
            }catch(\Throwable){throw new \DomainException('Sandbox enrollment could not be saved.');}
            $phase='sandbox_enrollment_finished';return $saved;
        }finally{$review->finish($phase);}
    }

    private function freshSandbox(int $serverId,bool $lock=false): void
    {
        $server=$this->server($serverId,$lock);
        // Inspect extension-owned retained state, not native application tables or framework internals.
        $prefix=DB::connection()->getTablePrefix();
        foreach(Schema::getTableListing(null,false) as $listed){
            if($prefix!==''&&!str_starts_with($listed,$prefix)){continue;}
            $table=$prefix===''?$listed:substr($listed,strlen($prefix));
            if(!str_starts_with($table,'domains_')||in_array($table,[StaffSettingsReview::KEYS,AuditArchives::TABLE],true)
                ||!Schema::hasColumn($table,'server_id')){continue;}
            $q=DB::table($table)->where('server_id',$serverId);if($lock){$q->lockForUpdate();}
            if($q->exists()){throw new \DomainException('Sandbox enrollment requires a fresh server.');}
        }
        $q=$server->settings()->whereRaw("`key` LIKE ? ESCAPE '!'",['domains!_%'])->whereNotIn('key',[StaffSettingsReview::KEY,'domains_setup_note']);
        if($lock){$q->lockForUpdate();}if($q->exists()){throw new \DomainException('Sandbox enrollment requires a fresh server.');}
    }

    private function uniqueSandboxKey(#[\SensitiveParameter] array $candidate,bool $lock=false): void
    {
        $q=Account::where('registrar_key','namesilo')->orderBy('server_id');if($lock){$q->lockForUpdate();}
        foreach($q->get() as $account){
            $enrolled=NameSiloSandbox::enrolled($account);
            try {
                $sq=Server::whereKey($account->server_id);if($lock){$sq->lockForUpdate();}$server=$sq->first();
                $marked=$server?->settings()->where('key',NameSiloSandbox::KEY)->exists()??false;
            }catch(\Throwable){throw new \DomainException('Existing sandbox enrollment unavailable.');}
            // The encrypted facts row is a durable enrollment marker even when the account identity
            // itself can no longer be decrypted. Never treat an unreadable enrollment as absent.
            if(!$enrolled&&!$marked){continue;}
            try {
                if(!$server){throw new \DomainException;}
                $facts=json_decode($this->secret((int)$account->server_id,NameSiloSandbox::KEY,$server,!$lock),true,8,JSON_THROW_ON_ERROR);
                $credentials=json_decode($this->secret((int)$account->server_id,self::CREDENTIALS,$server,!$lock),true,8,JSON_THROW_ON_ERROR);
                NameSiloSandbox::validate($account,$facts,$credentials);
            }catch(\Throwable){throw new \DomainException('Existing sandbox enrollment unavailable.');}
            if(hash_equals($facts['key_fingerprint'],$candidate['key_fingerprint'])){throw new \DomainException('Sandbox key already enrolled.');}
        }
    }

    /** Staff-only local projection. Corrupt, copied or edited facts reveal no saved label. */
    public function sandboxFacts(Account $account): array
    {
        try {
            if(!NameSiloSandbox::enrolled($account)){throw new \DomainException;}
            $credentials=$this->configuration($account);
            $facts=json_decode($this->secret((int)$account->server_id,NameSiloSandbox::KEY),true,8,JSON_THROW_ON_ERROR);
            NameSiloSandbox::validate($account,$facts,$credentials);
            return ['Enrollment'=>'OTE only · immutable','Account label (staff-entered, unconfirmed)'=>$facts['label'],
                'Account ownership'=>'NameSilo did not confirm which account this key belongs to.',
                'Key check'=>'At enrollment, NameSilo OTE accepted this key in one read-only balance request. That balance was not saved. This row records only the enrollment check. Later Read balance, Refresh now and scheduled reads appear in Registrar balances. None confirms account ownership.',
                'Key or label change'=>'To change the key or label, use a different OTE key on a fresh Domains server. Each key can be enrolled once on this site. Holdings, acceptances and operations stay with the old enrollment.'];
        }catch(\Throwable){return ['Enrollment'=>'Unavailable · not a current OTE enrollment. This server may be off or unavailable, OTE enrollment may be off on this site, saved enrollment settings may have changed, or this binding was never enrolled. The label is hidden.',
            'Account ownership'=>'NameSilo did not confirm which account this key belongs to.'];}
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

    public function setProcessing(int $serverId, bool $enabled, ?\Closure $acceptance=null): void
    {
        if($enabled&&app()->bound(Runtime::class)&&!$acceptance){throw new \DomainException('Installed activation requires staff review.');}
        DB::transaction(function () use ($serverId, $enabled,$acceptance): void {
            $server=$this->server($serverId, true);
            $account = Account::where('server_id', $serverId)->lockForUpdate()->firstOrFail();
            if ($enabled) { $acceptance?->__invoke($server,$account);$this->configuration($account, true,$server); }
            $account->processing_enabled = $enabled; Account::withVerifiedBindingWrite(static fn () => $account->save());
        });
    }

    public function reader(int $serverId, bool $balanceOnly = false): RegistrarDriver
    {
        $this->server($serverId);
        $account = Account::where('server_id', $serverId)->firstOrFail();
        return $this->registry->reader($this->binding($account), $this->configuration($account, true), $balanceOnly);
    }

    /** Bound real reads for one targeted, paid TEST acquisition preflight. */
    public function registrationPreflightReader(AccountBinding $expected): RegistrarDriver
    {
        if(DB::transactionLevel()!==0||!(EnomTestRegistration::enabled($expected)||EnomTestTransfer::enabled($expected))){
            throw new \DomainException('TEST registration preflight is unavailable.');
        }
        $this->server($expected->serverId);$account=Account::where('server_id',$expected->serverId)->sole();
        $expected->requireMatch($this->binding($account));
        return $this->registry->registrationPreflightReader($expected,$this->configuration($account,true));
    }

    /** Pure opt-in factory. Plaintext is loaded only inside the private protocol exchange. */
    public function enomRuntimeFactory(AccountBinding $expected, \Paymenter\Extensions\Servers\Domains\Contracts\Clock $clock,
        WriteFeatures $features, WriteAcceptance $accepted, RenewalAcceptance $renewalAccepted,
        TransferPrecheck $precheck, bool $managementAccepted=false, bool $testManagement=false): \Closure
    {
        return \Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::make($expected,$this,$clock,$features,$accepted,
            $renewalAccepted,$precheck,function(bool $write)use($expected):array {
                OperationClaims::outside();$this->server($expected->serverId);
                $account=Account::where('server_id',$expected->serverId)->sole();
                $expected->requireMatch($this->binding($account));
                if ($write&&!$account->processing_enabled) { throw new RuntimeIneligible(RuntimeReason::ProcessingOff); }
                return $this->configuration($account,true);
            },$managementAccepted,$testManagement);
    }

    /** Local credential/site validation only; never resolves a provider reader or sends a request. */
    public function configurationAvailable(int $serverId): bool
    { return $this->configurationStatus($serverId) === 'matched'; }

    public function hasRegistrar(string $registrar): bool
    {return in_array($registrar,$this->registry->keys(),true);}

    /** Safe local reason codes for staff recovery; no credential or provider detail leaves this check. */
    public function configurationStatus(int $serverId): string
    {
        try {
            $server = Server::find($serverId);
            if (!$server || $server->extension !== 'Domains') { return 'server_unavailable'; }
            if (!$server->enabled) { return 'server_disabled'; }
            $account = Account::where('server_id', $serverId)->first();
            if (!$account) { return 'not_configured'; }
            $binding = $this->binding($account);
            if ($binding->environment === Environment::Live) {
                try { $site = self::site(); } catch (\Throwable) { return 'site_invalid'; }
                if ($account->live_site === null) { return 'site_unbound'; }
                if ($account->live_site !== $site) { return 'site_mismatch'; }
            }
            $this->configuration($account, true);
            return 'matched';
        } catch (\Throwable) { return 'configuration_unavailable'; }
    }

    /** Named adoption factory: accepted credentials and exact site guard, independent of spending. */
    public function adoptionReader(\Paymenter\Extensions\Servers\Domains\Data\AccountBinding $expected,
        \Paymenter\Extensions\Servers\Domains\Contracts\Clock $clock,
        \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Transport $wire): \Paymenter\Extensions\Servers\Domains\Registrars\Enom\EnomAdoptionReader
    {
        if (DB::transactionLevel() !== 0 || $expected->registrarKey !== 'enom') { throw new \DomainException('Adoption read unavailable.'); }
        $this->registry->requireDomainReads($expected->registrarKey);
        $this->server($expected->serverId);
        $account = Account::where('server_id', $expected->serverId)->sole();
        $expected->requireMatch($this->binding($account));
        $credentials = $this->configuration($account, true);
        return new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\EnomAdoptionReader($expected,
            $credentials['uid'], $credentials['token'], $wire, $clock, Runtime::limiter());
    }

    /** Guard for later engine claim/dispatch; never grants admission by itself. */
    public function requireProcessing(AccountBinding $expected): void
    {
        $this->server($expected->serverId);
        $account = Account::where('server_id', $expected->serverId)->firstOrFail();
        $expected->requireMatch($this->binding($account));
        $this->configuration($account, true);
        if (!$account->processing_enabled) { throw new RuntimeIneligible(RuntimeReason::ProcessingOff); }
    }

    /** Caller holds these server/account rows in its checkout/claim transaction; never re-query a snapshot. */
    public function requireProcessingLocked(Server $server, Account $account, bool $advisory = false): void
    {
        if (DB::transactionLevel() === 0) { throw new \LogicException('Locked processing requires an outer transaction.'); }
        if (!$server->enabled || $server->extension !== 'Domains' || (int) $account->server_id !== (int) $server->id) {
            throw new \DomainException('Locked registrar server is unavailable.');
        }
        $this->binding($account);
        $this->configuration($account, true, $server, $advisory);
        if (!$account->processing_enabled) { throw new RuntimeIneligible(RuntimeReason::ProcessingOff); }
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

    private function configuration(Account $account, bool $requireSite = false, ?Server $lockedServer = null, bool $advisory = false): array
    {
        try {
            $binding = $this->binding($account);
            if ($requireSite && $binding->environment === Environment::Live && $account->live_site !== self::site()) {
                throw new \DomainException('Live mode is not bound to this site.');
            }
            $credentials = json_decode($this->secret((int) $account->server_id, self::CREDENTIALS, $lockedServer, $advisory), true, 8, JSON_THROW_ON_ERROR);
            $credentials = $this->registry->credentials($binding->registrarKey, $credentials);
            if (!hash_equals($this->secret((int) $account->server_id, self::VERIFIED, $lockedServer, $advisory), $this->digest($binding, $credentials))) {
                throw new \DomainException('Registrar credentials changed without verified rotation.');
            }
            if(NameSiloSandbox::enrolled($account)){
                $facts=json_decode($this->secret((int)$account->server_id,NameSiloSandbox::KEY,$lockedServer,$advisory),true,8,JSON_THROW_ON_ERROR);
                NameSiloSandbox::validate($account,$facts,$credentials);
            }elseif($account->registrar_key==='namesilo'&&($lockedServer??$this->server((int)$account->server_id))->settings()->where('key',NameSiloSandbox::KEY)->exists()){
                throw new \DomainException('Sandbox enrollment cannot be rebound.');
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

    private function secret(int $serverId, string $key, ?Server $lockedServer = null, bool $advisory = false): string
    {
        $query = ($lockedServer ?? $this->server($serverId))->settings()->where('key', $key);
        if ($lockedServer && !$advisory) { $query->lockForUpdate(); }
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
