<?php
// Synthetic admitted bridges; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/write-fixture.php';
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{RegistrarFactories, RegistrarRegistry, WriteAcceptance, WriteFeatures};
$off = new WriteFeatures;
foreach ([Action::Register, Action::Transfer, Action::Renew] as $action) { commerceReject(fn () => $off->requireEnabled($action)); }
commerceReject(fn () => $off->requireManagement());
$bridge = $build($enomBinding, $off); $op = $new('write-disabled.com');
$result = $engine->execute($op->id); same('held', $result->state, 'Disabled writes hold'); same('write_disabled', $result->safe_code, 'Neutral reason');
same(null, $result->attempt, 'Disabled write creates no attempt'); same(0, $preflight->calls, 'Disabled gate blocks read transport too'); same([], $transport->calls, 'No write transport');
$stale = $new('disabled-stale.com'); $claims->claim($stale->id);
$clock->instant = $clock->now()->modify('+361 seconds'); $stale = $engine->execute($stale->id);
same('uncertain', $stale->state, 'Disabled gate still quarantines expired workers'); same('stale_claim', $stale->safe_code, 'Stale worker reason');
same([], $transport->calls, 'Quarantine never sends'); $clock->instant = $clock->now()->modify('-361 seconds');
$bridge = $build($enomBinding); $op = $new('bridge-enom.com');
$result = $engine->execute($op->id); same(1, count($transport->calls), 'Enom bridge called once');
same('uncertain', $result->state, 'Bridge does not interpret outcome'); $call = $transport->calls[0];
same('https://resellertest.enom.com/interface.asp', $call['origin'], 'Fixed test origin'); same(25000, $call['timeoutMs'], 'Full bounded deadline');
same($op->payload, $call['payload'], 'Only locked frozen payload reaches wire port');
same('99.00', $call['op']->authorizedCost->amount, 'No dispatch cost floor');
commerceReject(fn () => $bridge->register($call['op'], $call['context']));
commerceReject(fn () => $bridge->register(clone $call['op'], $call['context']));
commerceReject(fn () => $bridge->register($call['op'], new RequestContext($enomBinding, RequestOrigin::Background, $call['context']->deadline, new RequestBudget(1), $clock)));
$forged = new AdmittedOperation($enomBinding, new Query('forged.com', Action::Register, 1), 'forged', 'claim', 1, 'funding', str_repeat('a', 64), new Money('1.00', 'USD'));
commerceReject(fn () => $bridge->register($forged, $call['context']));
// Holding the transport itself is insufficient: its final entry consumes a live engine grant.
$accepted = new WriteAcceptance($enomBinding, true, true, 25);
commerceReject(fn () => $transport->register($forged, $call['context'], $engine, new WriteFeatures(registration: true), $accepted));
commerceReject(fn () => $transport->register($call['op'], $call['context'], $engine, new WriteFeatures(registration: true), $accepted));
try { $transport->sendRegistration($forged, $call['context'], [], 'https://invalid.example', 1); expect(false, 'Wire method must be inaccessible'); } catch (Error) {}
commerceReject(fn () => $bridge->transferIn($call['op'], $call['context'])); commerceReject(fn () => $bridge->renew($call['op'], $call['context']));
same(1, count($transport->calls), 'Descriptors, clones, contexts and other methods confer no authority');
$transport->fail = true; $build($enomBinding); $op = $new('bridge-lost.com'); $result = $engine->execute($op->id); $transport->fail = false;
same(null, $result->attempt['execution'], 'Thrown transport retains recorded-null uncertainty'); same('uncertain', $result->state, 'No retry on transport exception');
$engine->execute($op->id); same(2, count($transport->calls), 'No replay');
// NameSilo profile proof is unavailable by default even with explicit catalogue acceptance.
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $clock->now());
$product = $native->product($tlds[1], 'register');
$nsNew = static function ($name) use ($product, $user) {
    $cart = commerceCart($user); commerceAdd($cart, $product, $name); commerceCheckout($user, $cart);
    $op = Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain', $name)->sole()->operations()->sole();
    Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
    App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']); return $op;
};
$build($nsBinding, acceptance: new WriteAcceptance($nsBinding, true, true, 25));
$op = $nsNew('ns-proof-missing.com'); $result = $engine->execute($op->id); same(2, count($transport->calls), 'Missing fresh contactAdd/readback proof blocks NameSilo');
same('held', $result->state, 'Missing profile proof refuses before seal even with a synthetic preflight');
same('absent', $result->attempt['seal'], 'Profile refusal cannot become possibly sent');
same('contact_profile_proof_required', $result->safe_code, 'Neutral profile hold reason');
$bridge = $build($nsBinding); $op = $nsNew('bridge-ns.com'); $result = $engine->execute($op->id);
same(3, count($transport->calls), 'Synthetic accepted NameSilo port called once'); same('uncertain', $result->state, 'No business completion');
same('https://sandbox.namesilo.com/apibatch', $transport->calls[2]['origin'], 'Explicit automated batch origin');
$registry = new RegistrarRegistry; RegistrarFactories::install($registry);
commerceReject(fn () => $registry->credentialKeys('namesilo')); commerceReject(fn () => $registry->credentialKeys('enom'));
echo "Admitted dual-driver write bridge checks passed\n"; $nativeSuccessful = true;
