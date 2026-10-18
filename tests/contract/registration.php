<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use OteContract\GuardedTransport;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, Environment, ExecutionRecord, Query, RequestBudget, RequestContext, RequestOrigin, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver, Registration, Writer};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\RegistrarRequest;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CatalogueAcceptances, DomainOperations, OperationClaims, RegistrarRegistry, RegistrationPreflight, RegistrationProfiles, WriteAcceptance, WriteFeatures};

// Native funding/account identity/catalogue policy are synthetic and disposable. No real account
// identity or installed-runtime acceptance is inferred. Actual protocol reads use the REAL driver.
$wire->check('stage', 'native_setup');
require dirname(__DIR__).'/Support/registration-fixture.php';
// Re-date only disposable catalogue evidence and its checkout clock; no provider facts are invented.
$now = new DateTimeImmutable('now', new DateTimeZone('UTC')); $clock->instant = $now;
$binding = $bindingFor(1); $rates = [];
foreach ([['register', '6.00'], ['renew', '8.00'], ['transfer', '7.00']] as [$action, $cost]) {
    $rates[] = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueRate($action, 1, $cost, 'synthetic', $now, $now->modify('+1 day'));
}
$prices->import(new \Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry($binding, 'com', $rates, [1], true, true),
    CatalogueAcceptances::load($binding), static fn () => null, $now);
$native->publish($tlds[1]->id, $now); $tlds[1]->refresh();
config(['logging.default' => 'null', 'logging.channels.null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]);
$clock = new \Paymenter\Extensions\Servers\Domains\Data\SystemClock;
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $clock->now());
$cart = commerceCart($user); commerceAdd($cart, $native->product($tlds[1], 'register'), $wire->domain); commerceCheckout($user, $cart);
$op = Domain::where('domain', $wire->domain)->sole()->operations()->sole();
Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']);
$op->update(['payload' => $op->payload + ['nameservers' => ['ns1.namesilo.com', 'ns2.namesilo.com'], 'privacy' => false]]);
$binding = $bindingFor(1);
$registry = new RegistrarRegistry;
$verifier = new class implements AccountVerifier {
    public function verify(Environment $e, #[SensitiveParameter] array $c): string { return $c['token']; }
};
$registry->register('namesilo', ['token'], $verifier, static fn ($b, $c) =>
    new Driver(new Client($b, (string) getenv('NAMESILO_SANDBOX_API_KEY'), $wire), ['register', 'renew'], $clock, 120));
$realBindings = new AccountBindings($registry); $claims = new OperationClaims($realBindings, $clock);
$profileParameters = []; $profileId = null;
$authority = static function () use ($claims, &$op, $binding): bool {
    try { [$current] = $claims->profileAuthority($op); $binding->requireMatch($current); return true; }
    catch (Throwable) { return false; }
};
$exchange = static function ($origin, $command, $parameters, $ms) use ($wire, &$profileParameters, &$profileId): string {
    if ($command === 'contactAdd') { $profileParameters = $parameters; }
    $body = $wire->raw($origin, $command, $parameters, $ms);
    $data = json_decode($body, true);
    if ($command === 'contactAdd') {
        $id = $data['reply']['contact_id'] ?? null;
        $profileId = is_string($id) || is_int($id) ? (string) $id : null;
    }
    if ($command === 'contactList') {
        $wire->receipt['profile_verification'] = \OteContract\ProfileDiagnostics::inspect(is_array($data['reply'] ?? null) ? $data['reply'] : [], $profileParameters, $profileId);
    }
    return $body;
};
if (($mode ?? '') === '--profile-read') {
    [$op] = $claims->claim($op->id); $wire->check('profile_authority_before', $authority());
    $parameters = RegistrationProfiles::parameters(\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::validate('namesilo', $op->payload['contacts'])['registrant']);
    $body = $wire->raw('https://ote.namesilo.com/apibatch', 'contactList', []);
    $data = json_decode($body, true);
    $inventory = \OteContract\ProfileDiagnostics::inventory(is_array($data['reply'] ?? null) ? $data['reply'] : [], $parameters);
    $wire->receipt['profile_inventory'] = $inventory;
    $matching = array_filter($inventory['rows'], static fn ($row) => $row['identity_match'] && $row['default_nondefault'] && $row['id_valid']);
    $wire->check('profile_read_gate', count($matching) === 1);
    $wire->check('profile_authority_after', $authority());
    $wire->check('registration', 'profile_read_only_no_creation_or_purchase'); $nativeSuccessful = true; return;
}
$features = new WriteFeatures(registration: true);
$profiles = new RegistrationProfiles($binding, $claims, $clock, $exchange,
    static fn (string $predicate, bool $passed) => $wire->check('profile_'.$predicate, $passed));
$acceptance = new WriteAcceptance($binding, true, true, 25, $profiles);
$preflight = new RegistrationPreflight($realBindings, $features, $acceptance, $profiles);
$protocol = new Registration($binding, $profiles, $exchange);
$bridge = new Writer($binding, $features, $acceptance, static function () use (&$engine) { return $engine; }, $protocol);
$engine = new DomainOperations($claims, $preflight, $bridge, $clock);
$started = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$wire->check('stage', 'production_registration');
$result = $engine->execute($op->id);
$wire->check('registration_engine_state', $result->state);
$wire->check('profile_proof_retained', $profiles->hasProof($result));
$wire->check('profile_hold_category', $result->safe_code === 'contact_profile_proof_required' ? 'contact_profile_proof_required'
    : ($result->state === 'held' ? 'other_hold' : 'none'));
$registered = GuardedTransport::durableRegistration($result->fresh()->attempt, $wire->domain);
$wire->authorizeRenewal($registered);
$wire->check('registration', $registered ? 'accepted_via_real_admitted_protocol'
    : ($wire->attempted('registerDomain') ? 'unresolved_possibly_sent' : 'blocked_before_purchase'));
if (!$registered) {
    $wire->mismatch('registerDomain', 'production_registration_not_accepted'); $nativeSuccessful = true;
    if ($wire->attempted('registerDomain')) { $wire->check('purchase_unresolved', true); throw new RuntimeException('OTE purchase needs investigation.'); }
    return;
}

$raw = static fn ($command, $parameters) => $wire->raw('https://ote.namesilo.com/apibatch', $command, $parameters);
$reader = new Client($binding, (string) getenv('NAMESILO_SANDBOX_API_KEY'), $wire);
$context = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+900 seconds'), new RequestBudget(40), $clock);
$get = static function (RegistrarRequest $request) use ($reader, $context, $wire) {
    try { return $reader->read($request, $context); }
    catch (Throwable) { $wire->mismatch($request->command, 'production_parser_not_success'); return null; }
};
$info = $get(RegistrarRequest::domainInfo($wire->domain));
$wire->check('registration_auto_renew_off', $info?->value('auto_renew') === 'No');
$wire->check('registration_privacy_off', $info?->value('private') === 'No');
try { require __DIR__.'/write-checks.php'; }
finally { $nativeSuccessful = true; }
