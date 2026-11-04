<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/FakeRegistrar.php';

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, Disposition, DriverResult, Environment, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainReservations, FoundationSchema, RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;

(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class);
$reject = static function (callable $call): void {
    try { $call(); expect(false, 'Binding safety refusal escaped'); }
    catch (DomainException|InvalidArgumentException|Illuminate\Database\QueryException|LogicException $e) { /* expected */ }
};
$verifier = new class implements AccountVerifier {
    public int $calls = 0;
    public function verify(Environment $environment, #[SensitiveParameter] array $credentials): string
    {
        same(0, DB::transactionLevel(), 'No provider verification under database locks'); $this->calls++;
        return match ($credentials['token'] ?? $credentials['api_key'] ?? '') {
            'private-rotation-credential' => 'account-b', 'A1', 'A2' => 'account-a', 'A3' => 'ACCOUNT-A', 'B1' => 'account-b',
            default => throw new RuntimeException('private-provider-detail'),
        };
    }
};
$factory = static fn (AccountBinding $b, array $credentials): FakeRegistrar => new FakeRegistrar($b,
    new Capabilities($b, []), new DriverResult($b, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unverified));
$registry = new RegistrarRegistry;
$registry->register('enom', ['uid', 'token'], $verifier, $factory);
$registry->register('namesilo', ['api_key'], $verifier, $factory);
$reject(static fn () => $registry->register('enom', ['token'], $verifier, $factory));
$reject(static fn () => $registry->register('other', ['token'], $verifier, $factory));
$bindings = new AccountBindings($registry);
foreach ([1, 2, 3, 4] as $id) { Server::create(['id' => $id, 'name' => 'Synthetic', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]); }
$credentials = ['uid' => 'synthetic', 'token' => 'A1'];
$callsBefore = $verifier->calls;
try { $bindings->configure(1, 'enom', Environment::Test, ['token' => 'A1']); expect(false, 'Incomplete credentials accepted'); }
catch (InvalidArgumentException $error) {
    same(InvalidArgumentException::class, $error::class, 'Incomplete credential refusal class');
    same('Registrar credentials incomplete.', $error->getMessage(), 'Incomplete credential refusal message');
}
same($callsBefore, $verifier->calls, 'Incomplete credentials refuse before provider verification');
try { DB::transaction(static fn () => $bindings->configure(1, 'enom', Environment::Test, $credentials)); expect(false, 'Open transaction accepted'); }
catch (LogicException $error) {
    same(LogicException::class, $error::class, 'Open transaction refusal class');
    same('Account verification must run outside database locks.', $error->getMessage(), 'Open transaction refusal message');
}
same($callsBefore, $verifier->calls, 'Open transactions refuse before provider verification');
Server::findOrFail(2)->update(['enabled' => false]);
$reject(static fn () => $bindings->configure(2, 'enom', Environment::Test, $credentials));
Server::findOrFail(2)->update(['enabled' => true]);
try { $bindings->configure(1, 'enom', Environment::Test, ['uid' => 'synthetic', 'token' => 'invalid']); expect(false, 'Unknown ownership accepted'); }
catch (RuntimeException $e) { same('Registrar account ownership could not be verified.', $e->getMessage(), 'Verification failure redacted'); same(null, $e->getPrevious(), 'No private exception chained'); }
$callsBefore = $verifier->calls;
$b = $bindings->configure(1, 'enom', Environment::Test, $credentials);
same($callsBefore + 1, $verifier->calls, 'First save performs exactly one provider verification');
$account = Account::where('server_id', 1)->firstOrFail();
same($b->fingerprint, AccountBindings::binding($account)->fingerprint, 'Account binding is independent of catalogue acceptance');
$invalidBinding = clone $account; $invalidBinding->account_fingerprint = str_repeat('0', 64);
$reject(static fn () => AccountBindings::binding($invalidBinding));
same($callsBefore + 1, $verifier->calls, 'Local binding validation makes no provider reads');
same(false, $account->processing_enabled, 'Processing initially off');
same('account-a', $account->stable_identity, 'Verified stable identity stored');
expect(!str_contains($account->getRawOriginal('stable_identity'), 'account-a'), 'Identity first-save encrypted');
expect(!array_key_exists('account_fingerprint', $account->toArray()), 'Binding hash hidden');
foreach (Server::findOrFail(1)->settings as $setting) {
    expect($setting->encrypted, 'Native secret marked encrypted');
    $raw = DB::table('settings')->where('id', $setting->id)->value('value');
    expect(!str_contains($raw, 'synthetic') && is_array(json_decode(base64_decode($raw), true)), 'Native first-save ciphertext envelope');
}
same($b->fingerprint, $bindings->reader(1)->binding()->fingerprint, 'Registry routes bound reader');
$reject(static fn () => $bindings->requireProcessing($b));
expect(!app()->bound(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class), 'Uninstalled binding fixture');
$reject(static fn () => $bindings->setProcessing(1, true));
same(false, $account->fresh()->processing_enabled, 'Missing acceptance cannot enable processing');
$reject(static fn () => $bindings->setProcessing(1, true, static function (): never { throw new DomainException('Activation refused.'); }));
same(false, $account->fresh()->processing_enabled, 'Rejected acceptance rolls back processing');
$bindings->setProcessing(1, true, syntheticActivation()); $bindings->requireProcessing($b);
$rotated = $bindings->configure(1, 'enom', Environment::Test, ['uid' => 'synthetic', 'token' => 'A2']);
same($b->fingerprint, $rotated->fingerprint, 'Token rotation preserves identity');
same($b->fingerprint, $bindings->configure(1, 'enom', Environment::Test, ['uid' => 'synthetic', 'token' => 'A3'])->fingerprint, 'Enom UID case normalizes to the same account');
$reject(static fn () => $bindings->requireProcessing($b));
foreach ([['enom', Environment::Live, $credentials], ['enom', Environment::Test, ['uid' => 'synthetic', 'token' => 'B1']],
    ['namesilo', Environment::Test, ['api_key' => 'A1']]] as [$key, $env, $values]) {
    $reject(static fn () => $bindings->configure(1, $key, $env, $values));
}
same($b->fingerprint, $account->fresh()->account_fingerprint, 'Failed repurpose preserves binding');
ini_set('zend.exception_ignore_args', '0');
try { $bindings->configure(1, 'enom', Environment::Test, ['uid' => 'synthetic', 'token' => 'private-rotation-credential']); expect(false, 'Account mismatch escaped'); }
catch (InvalidArgumentException|DomainException $error) {
    foreach ($error->getTrace() as $frame) {
        foreach ($frame['args'] ?? [] as $argument) {
            if ($argument instanceof Closure) {
                $captures = (new ReflectionFunction($argument))->getStaticVariables();
                expect(!isset($captures['credentials']), 'Native callback trace exposes captured credentials');
            }
        }
    }
    $diagnostic = $error->getMessage().print_r($error->getTrace(), true).$error->getTraceAsString().json_encode($error->getTrace(), JSON_THROW_ON_ERROR);
    same(null, $error->getPrevious(), 'No captured native transaction exception retained');
    expect(!str_contains($diagnostic, 'private-rotation-credential'), 'Post-verification failure trace hides captured credentials');
}
ini_set('zend.exception_ignore_args', '1');
$reject(static fn () => $bindings->configure(2, 'enom', Environment::Test, $credentials));
same(0, Server::findOrFail(2)->settings()->count(), 'Duplicate account transaction rolls back');
$setting = Server::findOrFail(1)->settings()->where('key', 'domains_credentials')->firstOrFail();
$setting->value = json_encode(['uid' => 'synthetic', 'token' => 'B1']); $setting->save();
$reject(static fn () => $bindings->reader(1));
$bindings->setProcessing(1, false); // pause remains possible with invalid credentials
$setting->value = '{"token":"private-stored-setting",'; $setting->save();
ini_set('zend.exception_ignore_args', '0');
try { $bindings->reader(1); expect(false, 'Malformed stored credentials accepted'); }
catch (DomainException $error) {
    same(null, $error->getPrevious(), 'Stored parser exception discarded');
    expect(!str_contains(print_r($error->getTrace(), true).$error->getTraceAsString().json_encode($error->getTrace(), JSON_THROW_ON_ERROR), 'private-stored-setting'), 'Stored credential parse trace redacted');
}
ini_set('zend.exception_ignore_args', '1');
$bindings->configure(1, 'enom', Environment::Test, $credentials);
$setting = Server::findOrFail(1)->settings()->where('key', 'domains_credentials')->firstOrFail();
DB::table('settings')->where('id', $setting->id)->update(['encrypted' => false]);
$reject(static fn () => $bindings->reader(1));
$bindings->configure(1, 'enom', Environment::Test, $credentials);
foreach (['id' => 999, 'server_id' => 2, 'registrar_key' => 'namesilo', 'environment' => 'live', 'stable_identity' => 'other',
    'account_fingerprint' => str_repeat('f', 64), 'identity_fingerprint' => str_repeat('f', 64)] as $field => $value) {
    $reject(static fn () => $account->update([$field => $value])); $account->refresh();
}
$reject(static fn () => $account->delete());
$reject(static fn () => $account->update(['processing_enabled' => true])); $account->refresh();
$reject(static fn () => Account::create(['server_id' => 2, 'registrar_key' => 'enom', 'environment' => 'test', 'stable_identity' => 'unverified-label']));
$domain = DomainReservations::reserve(['domain' => 'example.com', 'service_id' => 1, 'user_id' => 1, 'status' => 'pending',
    'server_id' => 1, 'registrar_key' => 'enom', 'account_fingerprint' => $b->fingerprint], ['com']);
$reject(static fn () => $domain->update(['server_id' => 2])); $domain->refresh();
$reject(static fn () => $domain->update(['user_id' => 2])); $domain->refresh();
$op = Operation::create(['domain_id' => $domain->id, 'service_id' => 1, 'authority_generation' => 1,
    'server_id' => 1, 'registrar_key' => 'enom', 'account_fingerprint' => $b->fingerprint,
    'operation_key' => 'binding:operation', 'action' => 'register', 'state' => 'queued', 'currency' => 'USD']);
$reject(static fn () => $op->update(['authority_generation' => 2])); $op->refresh();
$reject(static fn () => Operation::create(array_replace($op->getAttributes(), ['id' => 2, 'operation_key' => 'invalid:generation', 'authority_generation' => null])));
DB::table('domains_domains')->where('id', $domain->id)->update(['authority_generation' => 0]); // retained/corrupt fixture
$reject(static fn () => Operation::create(array_replace($op->getAttributes(), ['id' => 2, 'operation_key' => 'invalid:zero', 'authority_generation' => 0])));
DB::table('domains_domains')->where('id', $domain->id)->update(['authority_generation' => 1]);
$reject(static fn () => Tld::create(['tld' => 'com', 'server_id' => 1, 'registrar_key' => 'namesilo', 'account_fingerprint' => $b->fingerprint]));
Server::findOrFail(1)->update(['extension' => 'Other']);
$reject(static fn () => $bindings->reader(1));
Server::findOrFail(1)->update(['extension' => 'Domains']);
config(['app.url' => 'https://live.example.invalid/']);
$live = $bindings->configure(3, 'enom', Environment::Live, $credentials);
$reject(static fn () => $bindings->reader(3));
$reject(static fn () => $bindings->setProcessing(3, true, syntheticActivation()));
$reject(static fn () => $bindings->confirmLiveSite(3, 'https://wrong.example.invalid'));
$liveAccount = Account::where('server_id', 3)->firstOrFail();
$reject(static fn () => $liveAccount->update(['live_site' => 'https://live.example.invalid'])); $liveAccount->refresh();
$reject(static fn () => $liveAccount->update(['processing_enabled' => true])); $liveAccount->refresh();
$bindings->confirmLiveSite(3, 'https://live.example.invalid');
$bindings->setProcessing(3, true, syntheticActivation()); $bindings->requireProcessing($live);
$bindings->reader(3);
config(['app.url' => 'https://copy.example.invalid']);
$reject(static fn () => $bindings->reader(3));
$reject(static fn () => $bindings->requireProcessing($live));
$reject(static fn () => $bindings->confirmLiveSite(3, 'https://copy.example.invalid'));
$ns = $bindings->configure(4, 'namesilo', Environment::Test, ['api_key' => 'B1']);
same('namesilo', $bindings->reader(4)->binding()->registrarKey, 'Second registrar routes independently');
$reject(static fn () => $bindings->requireProcessing(new AccountBinding('enom', 4, Environment::Test, 'account-b')));
$badRegistry = new RegistrarRegistry;
$badRegistry->register('enom', ['uid', 'token'], $verifier, static fn () => $factory($ns, ['api_key' => 'B1']));
$reject(static fn () => $badRegistry->reader($b, $credentials));
$oldAppKey = config('app.key');
config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
$reject(static fn () => $bindings->reader(1)); // decryption retained, credential HMAC key changed
config(['app.key' => $oldAppKey]);
$bindings->reader(1);
// Loss of the encryption key refuses reads, even if an identity label is known.
$oldEncrypter = Account::currentEncrypter();
Account::encryptUsing(new Illuminate\Encryption\Encrypter(str_repeat('z', 32), 'AES-256-CBC'));
try { $bindings->reader(1); expect(false, 'Wrong encryption key accepted'); }
catch (Illuminate\Contracts\Encryption\DecryptException) { /* expected */ }
finally { Account::encryptUsing($oldEncrypter); }
same('account-a', $account->fresh()->stable_identity, 'Original key restores retained identity');
// Local enrollment needs the real native settings encryption, review locks and retained lineage.
// A unit fixture cannot establish their atomic behavior together.
config(['domains.namesilo_account.environment' => 'live', 'app.url' => 'https://live.example.com']);
$localBoot = new RegistrarRegistry;
\Paymenter\Extensions\Servers\Domains\Services\RegistrarFactories::bootstrap($localBoot);
expect(in_array('namesilo', $localBoot->keys(), true), 'NameSilo local enrollment is composed without an identity verifier');
require __DIR__.'/Support/staff-panel.php';
use Paymenter\Extensions\Servers\Domains\Services\{LocalAccountEnrollment, StaffAuditSchema, StaffSettingsReview, SellTableSchema, SalesPolicySchema, NameSiloAccountGuard};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
SalesPolicySchema::install(); StaffAuditSchema::install();
$staff = staffUser(['admin.domains.view', 'admin.domains.manage-policy', StaffSettingsReview::permission('configure')]);
$review = static function (int $id) use ($staff): StaffSettingsReview {
    return new StaffSettingsReview((int) $staff->id, $id, null, 'configure',
        StaffSettingsReview::ticket((int) $staff->id, $id, null, 'configure', StaffSettingsReview::stamp($id, null)), 'Rotate the synthetic local account key');
};
$wire = new class implements DeadlineTransport {
    public array $calls = [];
    public string $body = '{"request":{"operation":"getAccountBalance"},"reply":{"code":300,"detail":"synthetic","balance":"0.00"}}';
    public ?Closure $during = null;
    public function send(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey): TransportResponse
    { throw new LogicException('Authentication requires a deadline.'); }
    public function sendWithin(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey, int $timeoutMs): TransportResponse
    {
        same(0, DB::transactionLevel(), 'Key authentication never holds database locks');
        $this->calls[] = [$environment, $context, $request->command, $request->parameters(), $timeoutMs];
        if ($this->during) { $during = $this->during; $this->during = null; $during(); }
        return new TransportResponse(200, $this->body);
    }
};
$auth = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\CredentialAuthenticator(Environment::Live, $wire);
$localRegistry = new RegistrarRegistry;
$localRegistry->register('namesilo', ['api_key'], new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\AccountVerifier, $factory, authenticator: $auth);
$local = new AccountBindings($localRegistry); app()->instance(AccountBindings::class, $local);
foreach ([10, 11, 12] as $id) { Server::forceCreate(['id' => $id, 'name' => 'Local registrar', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]); }
$before = count($wire->calls);
$reject(fn () => $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-one']));
same($before, count($wire->calls), 'Local enrollment requires staff review before authentication');
$firstReview = $review(10);
$localBinding = $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-one'], $firstReview);
same([[WireEnvironment::Live, WireContext::Interactive, 'getAccountBalance', [], 10000]], $wire->calls, 'Enrollment only authenticates through one bounded balance read');
foreach ([Environment::Live, Environment::Test] as $bootEnvironment) {
    config(['domains.namesilo_account.environment' => $bootEnvironment->value]);
    $bootRegistry = new RegistrarRegistry;
    LocalAccountEnrollment::bootstrap($bootRegistry, $wire);
    $before = count($wire->calls); $authenticated = false;
    try { $bootRegistry->authenticate('namesilo', $bootEnvironment, ['api_key' => 'placeholder-bootstrap-key']); $authenticated = true; }
    catch (DomainException) { /* Report missing bootstrap transport/composition through the assertion below. */ }
    expect($authenticated, 'The bootstrap-built authenticator must authenticate through the injected transport');
    same([[$bootEnvironment === Environment::Live ? WireEnvironment::Live : WireEnvironment::Sandbox,
        WireContext::Interactive, 'getAccountBalance', [], 10000]], array_slice($wire->calls, $before), 'Bootstrap authenticates once in the configured environment with a bounded balance read');
}
config(['domains.namesilo_account.environment' => 'live']);
$a = Account::where('server_id', 10)->sole(); $identity = $a->stable_identity;
expect(str_starts_with($identity, LocalAccountEnrollment::PREFIX), 'Local lineage is explicit, random and separate from provider identity');
expect(!str_contains($identity, 'placeholder-key'), 'Credentials never become local identity');
same([false, null], [$a->processing_enabled, $a->live_site], 'Authentication grants neither processing nor a live site');
$reject(fn () => $local->reader(10));
$local->confirmLiveSite(10, 'https://live.example.com');
$before = count($wire->calls);
expect($local->configurationAvailable(10), 'The original local composition accepts its saved account');
expect(!$bindings->configurationAvailable(10), 'A registry without local enrollment must refuse the saved local account');
same('configuration_unavailable', $bindings->configurationStatus(10), 'Readiness reports the missing local composition safely');
$reject(fn () => $bindings->reader(10));
same($before, count($wire->calls), 'Composition refusal performs no authentication');
$revision = $local->credentialRevision($localBinding);
$local->requireCredentialRevision($localBinding, $revision);
foreach (Server::findOrFail(10)->settings()->get() as $setting) {
    expect($setting->encrypted, 'Enrollment secrets, revision and audit are encrypted on first save');
    expect(!str_contains($setting->getRawOriginal('value'), 'placeholder-key-one'), 'Enrollment stores no plaintext credentials');
}
$facts = json_decode(Server::findOrFail(10)->settings()->where('key', LocalAccountEnrollment::KEY)->sole()->value, true, 8, JSON_THROW_ON_ERROR);
same(false, $facts['provider_confirmed_account'], 'Authentication makes no provider identity assertion');
$guardClock = new \Paymenter\Extensions\Servers\Domains\Data\SystemClock;
$guardContext = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($localBinding,
    \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Interactive, $guardClock->now()->modify('+10 seconds'),
    new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1), $guardClock);
NameSiloAccountGuard::check($localBinding, $guardContext, static fn () => throw new RuntimeException('Default contact must not be read for local enrollment'));
same(1, $guardContext->budget->remaining(), 'Local Live account guard needs no default email acceptance');
$local->setProcessing(10, true, syntheticActivation());
$rotated = $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-two'], $review(10));
same($localBinding->fingerprint, $rotated->fingerprint, 'Key rotation retains local budget and holding lineage');
same([$identity, 'https://live.example.com', false], [$a->fresh()->stable_identity, $a->fresh()->live_site, $a->fresh()->processing_enabled], 'Rotation preserves identity/site and pauses processing');
$reject(fn () => $local->requireCredentialRevision($localBinding, $revision));
foreach (['same-key' => ['placeholder-key-two'], 'A-B-A' => ['placeholder-key-one', 'placeholder-key-two']] as $scenario => $keys) {
    $holdingRevision = $local->holdingCredentialRevision($localBinding);
    $previousRevision = $local->credentialRevision($localBinding);
    foreach ($keys as $key) { $replacement = $local->configure(10, 'namesilo', Environment::Live, ['api_key' => $key], $review(10)); }
    expect($previousRevision !== $local->credentialRevision($localBinding), 'Reviewed replacement creates a new persisted revision');
    same($localBinding->fingerprint, $replacement->fingerprint, 'Same-key replacement retains holding identity');
    DB::transaction(static function () use ($local, $localBinding, $holdingRevision, $scenario): void {
        expect(!hash_equals($holdingRevision, $local->holdingCredentialRevision($localBinding)), $scenario.' replacement must refuse the captured holding revision despite identical final key bytes');
        same($local->credentialRevision($localBinding), $local->holdingCredentialRevision($localBinding), 'Local holding fence retains the persisted credential revision');
    });
}
$secondRevision = $local->credentialRevision($localBinding);
expect($secondRevision !== $revision, 'Rotation replaces the credential revision');
$reject(fn () => $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-one'], $firstReview));
$before = count($wire->calls);
$reject(fn () => $local->configure(10, 'namesilo', Environment::Test, ['api_key' => 'placeholder-key-three'], $review(10)));
same($before, count($wire->calls), 'Environment repurpose refuses before authentication');
$reject(fn () => $local->configure(11, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-two'], $review(11)));
same($before, count($wire->calls), 'Duplicate key cannot create a new local budget lineage');
$wire->during = static function (): void { Server::findOrFail(10)->settings()->create(['key' => 'domains_setup_note', 'value' => 'Concurrent staff edit', 'type' => 'string']); };
$reject(fn () => $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-three'], $review(10)));
same($secondRevision, $local->credentialRevision($localBinding), 'Concurrent setting edit cannot overwrite a newer reviewed snapshot');
$beforeRows = Account::count();
$wire->body = '{"request":{"operation":"getAccountBalance"},"reply":{"code":110,"detail":"placeholder-key-rejected"}}';
ini_set('zend.exception_ignore_args', '0');
try { $local->configure(11, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-rejected'], $review(11)); expect(false, 'Rejected authentication accepted'); }
catch (DomainException $error) {
    same('Registrar key authentication failed.', $error->getMessage(), 'Rejected authentication has a safe reason');
    same(null, $error->getPrevious(), 'Authentication retains no credential-bearing cause');
    expect(!str_contains(print_r($error->getTrace(), true), 'placeholder-key-rejected'), 'Authentication failure trace hides credentials');
}
ini_set('zend.exception_ignore_args', '1');
same($beforeRows, Account::count(), 'Failed authentication creates no account');
expect(!Server::findOrFail(11)->settings()->whereIn('key', ['domains_credentials', LocalAccountEnrollment::KEY])->exists(), 'Failed authentication saves no secrets');
$malformedKey = "placeholder-key-\xFF";
$malformedError = null;
$before = count($wire->calls);
$previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
try { $local->configure(11, 'namesilo', Environment::Live, ['api_key' => $malformedKey], $review(11)); }
catch (Throwable $error) { $malformedError = $error; }
finally { ini_set('zend.exception_ignore_args', $previousIgnoreArgs); }
expect($malformedError !== null, 'Malformed credential is refused');
for ($error = $malformedError; $error !== null; $error = $error->getPrevious()) {
    expect(!str_contains($error->getMessage(), $malformedKey), 'Malformed credential is absent from exception messages');
    expect(!str_contains(print_r($error->getTrace(), true), $malformedKey), 'Malformed credential is absent from exception trace arguments');
}
same(InvalidArgumentException::class, $malformedError::class, 'Malformed credential uses the safe validation exception');
same('Registrar credentials invalid.', $malformedError->getMessage(), 'Malformed credential uses the existing safe validation error');
same(null, $malformedError->getPrevious(), 'Malformed credential retains no original exception');
same($before, count($wire->calls), 'Malformed credential is rejected before authentication');
$wire->body = '{"request":{"operation":"getAccountBalance"},"reply":{"code":300,"detail":"synthetic","balance":"0.00"}}';
$other = $local->configure(12, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-other'], $review(12));
expect(!$other->matches($localBinding), 'Independent local accounts have distinct random lineage');
config(['app.url' => 'https://copied.example.com']);
$before = count($wire->calls);
$reject(fn () => $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-three'], $review(10)));
same($before, count($wire->calls), 'Copied site cannot rotate or recover the original live binding');
$reject(fn () => $local->requireCredentialRevision($localBinding, $secondRevision));
config(['app.url' => 'https://live.example.com']);
$credentialSetting = Server::findOrFail(10)->settings()->where('key', 'domains_credentials')->sole();
$credentialSetting->value = '{"api_key":"placeholder-key-tampered"}'; $credentialSetting->save();
expect(!$local->configurationAvailable(10), 'Unreviewed credential changes cannot authenticate the binding');
$reject(fn () => $local->requireCredentialRevision($localBinding, $secondRevision));
foreach (StaffSettingsReview::historyRows(Server::findOrFail(10)) as $entry) {
    expect(!str_contains(json_encode($entry, JSON_THROW_ON_ERROR), 'placeholder-key'), 'Audit contains no key or raw balance response');
}
// Retained OTE facts must survive switching from the old opt-in to local Test enrollment.
// Native encrypted settings and reviewed enrollment are needed to expose the cross-composition gap.
use Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox;
foreach ([20, 21] as $id) { Server::forceCreate(['id' => $id, 'name' => 'Retained OTE regression', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]); }
$oteCredentials = ['api_key' => 'placeholder-retained-ote-key'];
$oteFacts = NameSiloSandbox::facts(20, 'Synthetic retained OTE', $oteCredentials);
$oteIdentity = NameSiloSandbox::identity($oteFacts);
$oteAccount = Account::withVerifiedBindingWrite(static fn () => Account::create(['server_id' => 20,
    'registrar_key' => 'namesilo', 'environment' => 'test', 'stable_identity' => $oteIdentity, 'processing_enabled' => false]));
$oteBinding = new AccountBinding('namesilo', 20, Environment::Test, $oteIdentity);
foreach ([NameSiloSandbox::KEY => json_encode($oteFacts, JSON_THROW_ON_ERROR),
    'domains_credentials' => json_encode($oteCredentials, JSON_THROW_ON_ERROR),
    'domains_verified_credentials' => hash_hmac('sha256', $oteBinding->fingerprint.json_encode($oteCredentials, JSON_THROW_ON_ERROR), (string) config('app.key'))] as $key => $value) {
    $setting = Server::findOrFail(20)->settings()->make(['key' => $key]);
    $setting->encrypted = true; $setting->type = 'string'; $setting->value = $value; $setting->save();
}
config(['domains.namesilo_account.environment' => 'test', 'domains.namesilo_sandbox_enrollment.enabled' => false]);
$testRegistry = new RegistrarRegistry;
$testRegistry->register('namesilo', ['api_key'], new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\AccountVerifier, $factory,
    authenticator: new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\CredentialAuthenticator(Environment::Test, $wire));
$testLocal = new AccountBindings($testRegistry);
$before = count($wire->calls);
try { $testLocal->configure(21, 'namesilo', Environment::Test, $oteCredentials, $review(21)); expect(false, 'Retained OTE key was enrolled as another local Test account'); }
catch (DomainException $error) { same('This key is already enrolled. Rotate it on its existing local account.', $error->getMessage(), 'Retained OTE duplicate has the same safe refusal as a local duplicate'); }
same($before, count($wire->calls), 'Retained OTE duplicate refuses before authentication');
expect(!Account::where('server_id', 21)->exists(), 'Retained OTE duplicate saves no new lineage');
same($oteIdentity, $oteAccount->fresh()->stable_identity, 'Duplicate refusal preserves retained OTE identity');
// Retained facts with a different identity must block even an unrelated new Test key before authentication.
$oteSetting = Server::findOrFail(20)->settings()->where('key', NameSiloSandbox::KEY)->sole();
$changedOteFacts = $oteFacts; $changedOteFacts['label'] = 'Mismatched retained OTE identity';
$oteSetting->value = json_encode($changedOteFacts, JSON_THROW_ON_ERROR); $oteSetting->save();
try {
    $testLocal->configure(21, 'namesilo', Environment::Test, ['api_key' => 'placeholder-independent-test-key'], $review(21));
    expect(false, 'Mismatched retained OTE identity must refuse local Test enrollment');
} catch (DomainException $error) {
    same('Existing sandbox enrollment unavailable.', $error->getMessage(), 'Retained identity mismatch has a safe fail-closed refusal');
}
same($before, count($wire->calls), 'Retained identity mismatch refuses before authentication');
expect(!Account::where('server_id', 21)->exists(), 'Retained identity mismatch saves no new account');
$oteSetting->value = json_encode($oteFacts, JSON_THROW_ON_ERROR); $oteSetting->save();
$testLocal->configure(21, 'namesilo', Environment::Test, ['api_key' => 'placeholder-independent-test-key'], $review(21));
same($before + 1, count($wire->calls), 'A different Test key still authenticates with the old OTE composition disabled');
echo "Bindings: positive synthetic identity, immutable accounts/sites, encrypted settings and guarded rotation passed.\n";
$nativeSuccessful = true;
