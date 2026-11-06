<?php
// Adapted from Enom 444f31c transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-input-evidence-ready.php';
use App\Models\{Credit, InvoiceTransaction, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, ContactRecovery, ContactSetup, InvoiceBinding, NativeDomainGuard, HolderPricing, PricingRule, Rdap, Refunds, TransferInputs, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
// Every authority row changes only retained proof. Prepare one genuine paid native
// checkout and restore all committed native rows, including money and cancellation,
// before each fault; each original refusal still runs outside a transaction.
require_once __DIR__.'/Support/fixture-checkpoint.php';
$fixtureCandidate=$new(placeholderComFixtureName('transfer-refuse-placeholder.com'));
$restoreCandidate=nativeFixtureCheckpoint();
$recoverCandidate=static function()use($restoreCandidate,$fixtureCandidate,$clock,$now):Operation {
    $restoreCandidate();$clock->instant=$now;
    $candidate=$fixtureCandidate->fresh();
    same($fixtureCandidate->getRawOriginal(),$candidate->getRawOriginal(),'Each authority fault starts from the exact genuine native checkout');
    return $candidate;
};
foreach([['attempt'=>['execution'=>null]],['attempt'=>['seal'=>'sealed']],['attempt'=>['fallback'=>null]],['cost'=>['execution'=>null]],
    ['started_at'=>$now],['state'=>'uncertain'],['provider_order_id'=>'synthetic'],['funding'=>['claim'=>null]]] as $i=>$changes){
    $candidate=$recoverCandidate();$inputs->paymentAccepted($candidate->id);if(isset($changes['cost'])){$changes['cost']=[...$candidate->cost,...$changes['cost']];}$candidate->update($changes);$clock->instant=$now->modify('+30 days');
    commerceReject(fn()=>$inputs->expireMissingCode($candidate->id));commerceReject(fn()=>$inputs->submitCode($post($user,'replacement'),$candidate->id));$clock->instant=$now;
}
echo "Transfer payment evidence case passed.\n";$nativeSuccessful=true;
