<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/binding-local.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\LiveReservationPolicy;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, Money};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainReservations, LiveReservations, LocalAccountEnrollment, NameSiloSandbox, RegistrarRegistry};

// Real encrypted settings, reviewed saves, held operations and durable usage must survive together.
// A unit fixture cannot establish continuity across the old and current enrollment compositions.
config(['domains.namesilo_account.environment' => 'test', 'domains.namesilo_sandbox_enrollment.enabled' => false]);
$registry = new RegistrarRegistry;
$registry->register('namesilo', ['api_key'], new NameSilo\AccountVerifier, $factory,
    authenticator: new NameSilo\CredentialAuthenticator(Environment::Test, $wire));
$local = new AccountBindings($registry); app()->instance(AccountBindings::class, $local);
$policy = new class implements LiveReservationPolicy {
    public function limits(AccountBinding $binding): array { return ['daily_call_cap' => 20, 'daily_spend_limit_usd' => '10.00']; }
};
$usage = new LiveReservations($policy, Environment::Test);
foreach ([30, 31, 32] as $id) { Server::forceCreate(['id' => $id, 'name' => 'Synthetic OTE rotation', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]); }
$refuse = static function (callable $call): void {
    $refused = false;
    try { $call(); } catch (DomainException) { $refused = true; }
    expect($refused, 'Invalid rotation or duplicate enrollment must refuse');
};
foreach ([30 => true, 31 => false] as $id => $upgradeFirst) {
    $credentials = ['api_key' => 'placeholder-ote-original-'.$id];
    $facts = NameSiloSandbox::facts($id, 'Synthetic retained OTE', $credentials);
    $identity = NameSiloSandbox::identity($facts);
    $account = Account::withVerifiedBindingWrite(static fn () => Account::create(['server_id' => $id,
        'registrar_key' => 'namesilo', 'environment' => 'test', 'stable_identity' => $identity, 'processing_enabled' => false]));
    $binding = AccountBindings::binding($account);
    foreach ([NameSiloSandbox::KEY => json_encode($facts, JSON_THROW_ON_ERROR),
        'domains_credentials' => json_encode($credentials, JSON_THROW_ON_ERROR),
        'domains_verified_credentials' => hash_hmac('sha256', $binding->fingerprint.json_encode($credentials, JSON_THROW_ON_ERROR), (string) config('app.key'))] as $key => $value) {
        $setting = Server::findOrFail($id)->settings()->make(['key' => $key]);
        $setting->encrypted = true; $setting->type = 'string'; $setting->value = $value; $setting->save();
    }
    $retained = Server::findOrFail($id)->settings()->where('key', NameSiloSandbox::KEY)->sole()->getRawOriginal();
    $domain = DomainReservations::reserve(['domain' => 'rotation-'.$id.'.com', 'service_id' => $id, 'user_id' => $id,
        'status' => 'pending', 'server_id' => $id, 'registrar_key' => 'namesilo', 'account_fingerprint' => $binding->fingerprint], ['com']);
    $op = Operation::create(['domain_id' => $domain->id, 'service_id' => $id, 'authority_generation' => 1,
        'server_id' => $id, 'registrar_key' => 'namesilo', 'account_fingerprint' => $binding->fingerprint,
        'operation_key' => 'ote:rotation:'.$id, 'action' => 'register', 'state' => 'held', 'currency' => 'USD']);
    $holding = $domain->fresh()->getRawOriginal(); $operation = $op->fresh()->getRawOriginal();
    $usage->reserve($binding, new Money('1.25', 'USD'));
    $counters = $usage->usage($binding);
    same(['used' => 1, 'spend_micros' => 1250000], $counters, 'Retained OTE usage is nonempty before rotation');
    $ledger = DB::table('domains_enom_live_calls')->orderBy('account')->get()->map(static fn ($row) => (array) $row)->all();
    $revision = null;
    if ($upgradeFirst) {
        $local->configure($id, 'namesilo', Environment::Test, $credentials, $review($id));
        $revision = $local->credentialRevision($binding);
        $local->setProcessing($id, true, syntheticActivation());
    }
    // Both a same-key upgrade followed by rotation and a direct reviewed rotation must work.
    $before = count($wire->calls);
    $replacement = ['api_key' => 'placeholder-ote-replacement-'.$id];
    $rotated = null;
    try { $rotated = $local->configure($id, 'namesilo', Environment::Test, $replacement, $review($id)); }
    catch (DomainException) { /* The assertion identifies the unsupported retained-account rotation. */ }
    expect($rotated !== null, 'Reviewed OTE key rotation must succeed on the retained account');
    same($before + 1, count($wire->calls), 'Rotation authenticates exactly once outside locks');
    same($binding->fingerprint, $rotated->fingerprint, 'Rotation keeps the original binding');
    same([$identity, false, null], [$account->fresh()->stable_identity, $account->fresh()->processing_enabled, $account->fresh()->live_site], 'Rotation keeps TEST lineage and pauses processing');
    expect($local->configurationAvailable($id), 'Rotated retained enrollment is readable');
    $currentRevision = $local->credentialRevision($binding);
    if ($revision !== null) { $refuse(fn () => $local->requireCredentialRevision($binding, $revision)); }
    $local->requireCredentialRevision($binding, $currentRevision);
    same($counters, $usage->usage($binding), 'Rotation preserves call and spend usage');
    same($ledger, DB::table('domains_enom_live_calls')->orderBy('account')->get()->map(static fn ($row) => (array) $row)->all(), 'Rotation never rewrites or duplicates usage rows');
    same($holding, $domain->fresh()->getRawOriginal(), 'Rotation preserves the holding');
    same($operation, $op->fresh()->getRawOriginal(), 'Rotation preserves the held operation');
    same($retained, Server::findOrFail($id)->settings()->where('key', NameSiloSandbox::KEY)->sole()->getRawOriginal(), 'Original OTE facts remain byte-for-byte retained');
    $before = count($wire->calls);
    foreach ([$credentials, $replacement] as $duplicate) {
        $refuse(fn () => $local->configure(32, 'namesilo', Environment::Test, $duplicate, $review(32)));
    }
    same($before, count($wire->calls), 'Original and current keys cannot enroll another account before authentication');
    expect(!Account::where('server_id', 32)->exists(), 'Duplicate checks create no account');
    $refuse(fn () => $local->configure($id, 'namesilo', Environment::Live, $replacement, $review($id)));
    same($before, count($wire->calls), 'Retained OTE cannot move to Live');
    // Failed authentication must retain the last usable credentials and processing state.
    $wire->body = '{"request":{"operation":"getAccountBalance"},"reply":{"code":110,"detail":"synthetic refusal"}}';
    $refuse(fn () => $local->configure($id, 'namesilo', Environment::Test, ['api_key' => 'placeholder-refused-key'], $review($id)));
    same($currentRevision, $local->credentialRevision($binding), 'Failed rotation preserves the credential revision');
    $wire->body = '{"request":{"operation":"getAccountBalance"},"reply":{"code":300,"detail":"synthetic","balance":"0.00"}}';
    $local->configure($id, 'namesilo', Environment::Test, $credentials, $review($id));
    $refuse(fn () => $local->requireCredentialRevision($binding, $currentRevision));
    same($counters, $usage->usage($binding), 'Returning to the original key preserves usage');
    $enrollment = Server::findOrFail($id)->settings()->where('key', NameSiloSandbox::KEY)->sole();
    $bad = $facts; $bad['label'] = 'Changed retained facts';
    $enrollment->value = json_encode($bad, JSON_THROW_ON_ERROR); $enrollment->save();
    expect(!$local->configurationAvailable($id), 'Local enrollment cannot hide changed original OTE facts');
    $before = count($wire->calls);
    $refuse(fn () => $local->configure($id, 'namesilo', Environment::Test, $replacement, $review($id)));
    same($before, count($wire->calls), 'Damaged lineage refuses before authentication');
    $enrollment->value = json_encode($facts, JSON_THROW_ON_ERROR); $enrollment->save();
}
echo "binding-ote-rotation-test.php passed\n"; $nativeSuccessful = true;
