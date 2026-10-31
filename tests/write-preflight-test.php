<?php
// Fresh evidence regressions adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/write-fixture.php';
\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, CostUnit, Disposition, DriverResult, Environment, Evidence, Money, Query, QuoteEvidence, RejectionBasis, RequestBudget, RequestContext, RequestOrigin, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CatalogueAcceptances, DomainOperations, OperationClaims, RegistrarFactories, RegistrarRegistry, RegistrationPreflight, WriteAcceptance, WriteFeatures, WriteHeld};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
$op = $new(placeholderComFixtureName('fresh-preflight-placeholder.com')); $query = OperationClaims::query($op, $op->domain);
$state = (object) ['calls' => 0, 'change' => '', 'revoke' => false, 'readers'=>[]];
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
    $reader = new FakeRegistrar($b, new Capabilities($b, []), match ($state->change) {
        'unanswered' => new DriverResult($b, Disposition::Unknown, SendState::Sent, ResultReason::Unresolved),
        'unsupported' => new DriverResult($b, Disposition::Rejected, SendState::DefinitelyUnsent, ResultReason::Unsupported, rejectionBasis: RejectionBasis::DefinitelyUnsent),
        default => new DriverResult($b, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
            new Evidence(quote: $quote, balance: $state->change === 'balance' ? null : $balance,
                available: $state->change === 'available' ? null : $state->change !== 'taken', premium: $state->change === 'premium' ? null : $state->change === 'listed_premium')),
    });
    $state->readers[]=$reader;return $reader;
});
$freshBindings = new AccountBindings($registry); $features = new WriteFeatures(registration: true);
$acceptance = new WriteAcceptance($enomBinding, true, true, 25);
$fresh = new RegistrationPreflight($freshBindings, $features, $acceptance);
$ctx = static fn () => new RequestContext($enomBinding, RequestOrigin::Background, $clock->now()->modify('+60 seconds'), new RequestBudget(4), $clock);
// Each refusal keeps its own cause, so staff are sent to the fix that works.
foreach (['precision' => 'registrar_balance_insufficient', 'low' => 'registrar_balance_insufficient', 'currency' => 'registrar_read_failed',
    'cost' => 'registrar_price_missing', 'stale' => 'registrar_price_missing', 'term' => 'registrar_price_missing',
    'balance' => 'registrar_read_failed', 'available' => 'registrar_read_failed', 'premium' => 'registrar_read_failed', 'unanswered' => 'registrar_read_failed',
    'taken' => 'name_unavailable', 'listed_premium' => 'premium_name', 'unsupported' => 'order_not_supported'] as $change => $reason) {
    $state->change = $change;
    try { $fresh->inspect($op, $op->domain, $ctx()); throw new LogicException('Preflight accepted: '.$change); }
    catch (WriteHeld $held) { same($reason, $held->reason, 'Preflight cause: '.$change); }
}
$state->change = ''; $context = $ctx(); $ready = $fresh->inspect($op, $op->domain, $context);
same('99.00', $ready->cost->amount, 'Fresh cost can exceed frozen retail; no dispatch floor'); same(1, $context->budget->remaining(), 'Three bounded preflight reads');
$balanceRow=DB::table('domains_registrar_balances')->where('server_id',$op->server_id)->where('account_fingerprint',$op->account_fingerprint)->sole();
same(['100.00','USD',null,'preflight'],[$balanceRow->amount,$balanceRow->currency,$balanceRow->last_error_code,$balanceRow->source],'Successful registration preflight retains exact balance');
same(['availability','quote','balance'],end($state->readers)->calls,'Successful preflight performs exactly one balance read');
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
