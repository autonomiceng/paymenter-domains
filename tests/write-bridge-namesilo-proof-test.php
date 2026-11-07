<?php
// Synthetic admitted bridges; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/write-fixture.php';
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{RegistrarFactories, RegistrarRegistry, WriteAcceptance, WriteFeatures};
$off = new WriteFeatures;
foreach ([Action::Register, Action::Transfer, Action::Renew] as $action) { commerceReject(fn () => $off->requireEnabled($action)); }
commerceReject(fn () => $off->requireManagement());
// NameSilo profile proof is unavailable by default even with explicit catalogue acceptance.
activeFixtureSeller($sellers,'com',(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->value('revision'), $tlds[1]->server_id, true, true, $clock->now());
$product = $native->product($tlds[1], 'register');
$nsNew = static function ($name) use ($product, $user) {
    $cart = commerceCart($user); commerceAdd($cart, $product, $name); commerceCheckout($user, $cart);
    $op = Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain', $name)->sole()->operations()->sole();
    Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
    App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']); return $op;
};
$build($nsBinding, acceptance: new WriteAcceptance($nsBinding, true, true, 25,catalogue: $GLOBALS['caps']??null));
$op = $nsNew(placeholderComFixtureName('ns-proof-missing-placeholder.com')); $result = $engine->execute($op->id); same(0, count($transport->calls), 'Missing fresh contactAdd/readback proof blocks NameSilo');
same('held', $result->state, 'Missing profile proof refuses before seal even with a synthetic preflight');
same('absent', $result->attempt['seal'], 'Profile refusal cannot become possibly sent');
same('contact_profile_proof_required', $result->safe_code, 'Neutral profile hold reason');
$bridge = $build($nsBinding); $op = $nsNew(placeholderComFixtureName('bridge-ns-placeholder.com')); $result = $engine->execute($op->id);
same(1, count($transport->calls), 'Synthetic accepted NameSilo port called once'); same('uncertain', $result->state, 'No business completion');
same('https://ote.namesilo.com/apibatch', $transport->calls[0]['origin'], 'Explicit automated batch origin');
$registry = new RegistrarRegistry; RegistrarFactories::install($registry);
commerceReject(fn () => $registry->credentialKeys('namesilo')); commerceReject(fn () => $registry->credentialKeys('enom'));
$nativeSuccessful=true;echo "NameSilo profile proof blocks the native write bridge.\n";
