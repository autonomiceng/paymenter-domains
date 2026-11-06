<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native financial matrix; no provider calls.
declare(strict_types=1);
require __DIR__'/Support/enom-multiyear.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Environment, Money};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimitSchema, RenewalCycles};
[$op, $d, $b, $invoice] = $makeRenewal(placeholderComFixtureName('multiyear-test-placeholder.com'), true, 2, beforeBill: $review);
same('16.0000', $op->policy['renewal']['cost_cap']['amount'], 'Saved two-year ceiling is twice annual cost');
same($op->amount, $invoice->items()->sole()->price, 'Native invoice matches frozen customer total');
config(['domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true, 'domains.enom_writes.renewal' => true]);
$accepted = new \Paymenter\Extensions\Servers\Domains\Services\RenewalAcceptance($b, true, true, 25);
$admission = new \Paymenter\Extensions\Servers\Domains\Services\RenewalAdmission($authority, $renewBindings, $accepted, $clock);
$request = new \Paymenter\Extensions\Servers\Domains\Data\RenewalRequest($d->id, $d->authority_generation,
    $d->confirmed_revision, '2027-10-18', '456', 2, $op->operation_key);
$decision = $admission->check($request);
same(true, $decision->allowed(), 'Paid Enom TEST two-year debt admitted');
same('16.0000', $decision->cost->amount, 'Pre-send quote is annual cost times two');
// The final executor repeats that read and compares the full quote with the saved ceiling.
Cache::flush(); [$engine, $writer] = $compose($b); $state->price = '8.01'; $writes = $state->writes;
same('held', $engine->run($op->id, $writer)->state, 'Two-year quote above saved ceiling is held');
same('renewal_cost_overrun', $op->fresh()->safe_code, 'Over-limit reason retained');
same($writes, $state->writes, 'No send above the saved limit');
same([], (array) $op->fresh()->attempt, 'Over-limit debt remains unsealed');

same(false, TermSupport::admits('enom', 2, $op->policy['renewal'], environment: Environment::Live), 'Frozen TEST debt cannot admit Live two-year renewal');
echo "Enom TEST multiyear admission: ok\n";
$nativeSuccessful = true;
