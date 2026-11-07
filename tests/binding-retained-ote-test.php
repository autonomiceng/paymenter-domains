<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native binding coverage.
declare(strict_types=1);
require __DIR__.'/Support/binding-local.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, Disposition, DriverResult, Environment, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainReservations, FoundationSchema, RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
use Paymenter\Extensions\Servers\Domains\Services\{LocalAccountEnrollment, StaffAuditSchema, StaffSettingsReview, SalesPolicySchema, NameSiloAccountGuard};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox;
// Retained OTE facts must survive switching from the old opt-in to local Test enrollment.
// Native encrypted settings and reviewed enrollment are needed to expose the cross-composition gap.
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
echo "binding-retained-ote-test.php passed\n";$nativeSuccessful=true;
