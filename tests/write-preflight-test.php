<?php
// Fresh evidence regressions adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/write-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, CostUnit, Disposition, DriverResult, Environment, Evidence, Money, Query, QuoteEvidence, RequestBudget, RequestContext, RequestOrigin, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CatalogueAcceptances, DomainOperations, OperationClaims, RegistrarFactories, RegistrarRegistry, RegistrationPreflight, WriteAcceptance, WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
$op = $new('fresh-preflight.com'); $query = OperationClaims::query($op, $op->domain);
$state = (object) ['calls' => 0, 'change' => '', 'revoke' => false];
$verifier = new class implements AccountVerifier {
    public function verify(Environment $e, #[SensitiveParameter] array $credentials): string { return 'verified-stable-id'; }
};
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], $verifier, static function (AccountBinding $b, array $c) use ($state, $query, $clock): FakeRegistrar {
    same(0, DB::transactionLevel(), 'Fresh reads outside locks'); $state->calls++;
    $cost = new Money($state->change === 'precision' ? '999999999999999999.000002' : '99.00', 'USD');
    $balance = new Money($state->change === 'precision' ? '999999999999999999.000001' : ($state->change === 'low' ? '98.99' : '100.00'), $state->change === 'currency' ? 'EUR' : 'USD');
    $observed = $clock->now()->modify($state->change === 'stale' ? '-1 second' : '+0 seconds');
    $quote = new QuoteEvidence($state->change === 'term' ? new Query($query->name, $query->action, 3) : $query,
        $state->change === 'cost' ? null : $cost, CostUnit::TermTotal, 'synthetic', $observed, $clock->now()->modify('+120 seconds'));
    if ($state->revoke) { CatalogueAcceptances::save($b, 1, []); }
    return new FakeRegistrar($b, new Capabilities($b, []), new DriverResult($b, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
        new Evidence(quote: $quote, balance: $state->change === 'balance' ? null : $balance,
            available: $state->change === 'available' ? null : true, premium: $state->change === 'premium' ? null : false)));
});
$freshBindings = new AccountBindings($registry); $features = new WriteFeatures(registration: true);
$acceptance = new WriteAcceptance($enomBinding, true, true, 25);
$fresh = new RegistrationPreflight($freshBindings, $features, $acceptance);
$ctx = static fn () => new RequestContext($enomBinding, RequestOrigin::Background, $clock->now()->modify('+60 seconds'), new RequestBudget(4), $clock);
foreach (['precision', 'low', 'currency', 'cost', 'balance', 'stale', 'term', 'available', 'premium'] as $change) {
    $state->change = $change; commerceReject(fn () => $fresh->inspect($op, $op->domain, $ctx()));
}
$state->change = ''; $context = $ctx(); $ready = $fresh->inspect($op, $op->domain, $context);
same('99.00', $ready->cost->amount, 'Fresh cost can exceed frozen retail; no dispatch floor'); same(1, $context->budget->remaining(), 'Three bounded preflight reads');
$before = $state->calls;
commerceReject(fn () => (new RegistrationPreflight($freshBindings, new WriteFeatures, $acceptance))->inspect($op, $op->domain, $ctx()));
commerceReject(fn () => (new RegistrationPreflight($freshBindings, $features, new WriteAcceptance($enomBinding)))->inspect($op, $op->domain, $ctx()));
same($before, $state->calls, 'Default gates and missing USD/batch acceptance refuse before reads');
// Change the default seller after checkout: the original accepted holder still owns dispatch.
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $clock->now());
same('99.00', $fresh->inspect($op, $op->domain, $ctx())->cost->amount, 'Frozen holder ignores current seller');
// Revoke durable acceptance during reads; seal must refuse with zero wire calls.
$build($enomBinding); $engine = new DomainOperations($claims, $fresh, $proxy, $clock); $state->revoke = true;
$result = $engine->execute($op->id); same('held', $result->state, 'Acceptance checked again at seal');
same('absent', $result->attempt['seal'], 'Revoked acceptance never seals'); same([], $transport->calls, 'No send after seal-time revocation');
$production = new RegistrarRegistry; RegistrarFactories::install($production, $verifier, $verifier);
same(['uid', 'token'], $production->credentialKeys('enom'), 'Production Enom schema'); same(['api_key'], $production->credentialKeys('namesilo'), 'Production NameSilo schema');
[$verified] = $production->verify('namesilo', 999, Environment::Test, ['api_key' => 'synthetic']);
same('namesilo', $verified->registrarKey, 'Positive verifier required; no credential identity fallback');
echo "Fresh registration preflight, precision and seal acceptance checks passed\n"; $nativeSuccessful = true;
