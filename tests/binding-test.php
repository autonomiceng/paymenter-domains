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

FoundationSchema::install();
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
$reject(static fn () => $bindings->configure(1, 'enom', Environment::Test, ['token' => 'A1']));
$reject(static fn () => DB::transaction(static fn () => $bindings->configure(1, 'enom', Environment::Test, $credentials)));
Server::findOrFail(2)->update(['enabled' => false]);
$reject(static fn () => $bindings->configure(2, 'enom', Environment::Test, $credentials));
Server::findOrFail(2)->update(['enabled' => true]);
try { $bindings->configure(1, 'enom', Environment::Test, ['uid' => 'synthetic', 'token' => 'invalid']); expect(false, 'Unknown ownership accepted'); }
catch (RuntimeException $e) { same('Registrar account ownership could not be verified.', $e->getMessage(), 'Verification failure redacted'); same(null, $e->getPrevious(), 'No private exception chained'); }
$b = $bindings->configure(1, 'enom', Environment::Test, $credentials);
$account = Account::where('server_id', 1)->firstOrFail();
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
$bindings->setProcessing(1, true); $bindings->requireProcessing($b);
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
$domain = DomainReservations::reserve(['domain' => 'x.com', 'service_id' => 1, 'user_id' => 1, 'status' => 'pending',
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
$reject(static fn () => $bindings->setProcessing(3, true));
$reject(static fn () => $bindings->confirmLiveSite(3, 'https://wrong.example.invalid'));
$liveAccount = Account::where('server_id', 3)->firstOrFail();
$reject(static fn () => $liveAccount->update(['live_site' => 'https://live.example.invalid'])); $liveAccount->refresh();
$reject(static fn () => $liveAccount->update(['processing_enabled' => true])); $liveAccount->refresh();
$bindings->confirmLiveSite(3, 'https://live.example.invalid');
$bindings->setProcessing(3, true); $bindings->requireProcessing($live);
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
echo "Bindings: positive synthetic identity, immutable accounts/sites, encrypted settings and guarded rotation passed.\n";
$nativeSuccessful = true;
