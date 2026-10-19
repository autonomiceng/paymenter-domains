<?php
// Synthetic Enom 444f31c registration/follow-up scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/operation-fixture.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, CostUnit, Disposition, DriverResult, Environment, Evidence, Money, QuoteEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CatalogueAcceptances, DomainOperations, OperationClaims, RegistrarRegistry, RegistrationPreflight, RegistrationProfiles, WriteAcceptance, WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
\Paymenter\Extensions\Servers\Domains\Services\ContactProfileSchema::install();
$registration = (object) ['query' => null, 'calls' => [], 'reads' => [], 'purchases' => [], 'mode' => '', 'profile' => [], 'id' => 100, 'diagnostics' => []];
$registry = new RegistrarRegistry;
$verifier = new class implements AccountVerifier {
    public function verify(Environment $e, #[SensitiveParameter] array $credentials): string { return $credentials['token']; }
};
foreach (['enom', 'namesilo'] as $key) {
    $registry->register($key, ['token'], $verifier, static function (AccountBinding $b, array $c) use ($registration, $clock): FakeRegistrar {
        same(0, DB::transactionLevel(), 'Read factory outside locks');
        $registration->reads[] = $registration->query->name;
        $quote = new QuoteEvidence($registration->query, new Money('99.00', 'USD'), CostUnit::TermTotal, 'synthetic', $clock->now(), $clock->now()->modify('+120 seconds'));
        return new FakeRegistrar($b, new Capabilities($b, []), new DriverResult($b, Disposition::Accepted, SendState::Sent,
            ResultReason::Acknowledged, new Evidence(quote: $quote, balance: new Money('100.00', 'USD'), available: true, premium: false)));
    });
}
$freshBindings = new AccountBindings($registry);
$makeEngine = static function ($binding, $exchange, ?WriteFeatures $features = null) use (&$engine, &$bridge, &$protocol, &$fresh, &$acceptance, &$profiles, $claims, $freshBindings, $clock, $registration) {
    $features ??= new WriteFeatures(registration: true);
    $profiles = new RegistrationProfiles($binding, $claims, $clock, $exchange,
        static function (string $predicate, bool $passed) use ($registration): void { $registration->diagnostics[$predicate] = $passed; });
    $acceptance = new WriteAcceptance($binding, true, true, 25, $profiles);
    $fresh = new RegistrationPreflight($freshBindings, $features, $acceptance, $profiles);
    $class = 'Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($binding->registrarKey === 'enom' ? 'Enom' : 'NameSilo').'\\';
    $protocol = $binding->registrarKey === 'enom' ? new ($class.'Registration')($binding, $exchange) : new ($class.'Registration')($binding, $profiles, $exchange);
    $bridge = new ($class.'Writer')($binding, $features, $acceptance, static function () use (&$engine) { return $engine; }, $protocol);
    $engine = new DomainOperations($claims, $fresh, $bridge, $clock);
    Cache::forget('domains-write-slot:'.$binding->fingerprint);
};
$runRegistration = static function (Operation $op) use (&$engine, $registration) {
    $op->update(['payload' => $op->payload + ['nameservers' => ['ns1.provider.net', 'ns2.provider.net'], 'privacy' => false]]);
    $registration->query = OperationClaims::query($op, $op->domain); return $engine->execute($op->id);
};
$assertRetained = static function ($result, array $corrections): void {
    same('pending', $result->state, 'Accepted purchase awaits unique charge');
    same(true, $result->attempt['registration']['accepted'], 'Durable acceptance');
    same('awaiting_unique_charge', $result->attempt['registration']['financial_completion'], 'Slice22 correlation outstanding');
    same($corrections, array_keys($result->attempt['corrections']), 'Durable separately authorized correction work');
    foreach ($result->attempt['corrections'] as $work) { same(['state' => 'authorization_required', 'readback_required' => true], $work, 'No implicit correction send'); }
    same($result->domain->domain, $result->domain->reservation_name, 'Reservation retained');
    same('registration_pending', $result->domain->status, 'No expiry or active financial anchor yet');
    same(null, $result->cost['actual'] ?? null, 'Purchase amount never silently accepted as actual cost');
    same('paid', App\Models\Invoice::find($result->invoice_id)->status, 'Native paid invoice retained');
    commerceReject(fn () => $result->update(['attempt' => array_replace($result->attempt, ['registration' => null])])); $result->refresh();
};
$bindingFor = static fn ($i) => CatalogueAcceptances::binding(Account::where('server_id', $tlds[$i]->server_id)->sole());
