<?php
// Synthetic Enom 444f31c claim/seal regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, OperationPreflight};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, Capabilities, Capability, DispatchReadiness, Disposition, DriverResult, Evidence, Money, RequestContext, ResultReason, SendState, Support};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, DomainOperations, InvoiceBinding, OperationClaims};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

$assertNoSend($new('unpaid.com', false)); $assertNoSend($new('setup.com', true, false));
$op = $new('ordinary.com'); $result = $engine->execute($op->id);
same('uncertain', $result->state, 'Unknown response holds obligation'); same('ordinary.com', $result->domain->reservation_name, 'Unknown holds name');
same('99.00', $writer->last->authorizedCost->amount, 'Known cost above paid retail is allowed: no dispatch margin floor');
same('possibly_sent', $result->attempt['execution']['send_state'], 'Normalized execution retained');
$assertNoSend($result, 'uncertain'); commerceReject(fn () => $engine->consume($writer->last, $writer->context));
$forged = clone $writer->last; commerceReject(fn () => $engine->consume($forged, $writer->context));
foreach (['execution', 'dispatch_attempted_at', 'claim_key', 'seal'] as $key) {
    $attempt = $result->attempt; unset($attempt[$key]); commerceReject(fn () => $result->fresh()->update(['attempt' => $attempt]));
}
$op = $new('preseal.com'); $preflight->fail = true; $result = $assertNoSend($op); $preflight->fail = false;
same('absent', $result->attempt['seal'], 'Failure before seal retains absent marker'); expect(!array_key_exists('execution', $result->attempt), 'Preseal execution stays absent');
$op = $new('postseal.com'); $writer->fail = true; $result = $engine->execute($op->id); $writer->fail = false;
same('uncertain', $result->state, 'Lost response is uncertain'); expect(array_key_exists('execution', $result->attempt) && $result->attempt['execution'] === null, 'Postseal recorded-null differs from absent');
$assertNoSend($result, 'uncertain');
$op = $new('ack.com'); $writer->disposition = Disposition::Accepted; same('uncertain', $engine->execute($op->id)->state, 'Ack is not business completion'); $writer->disposition = Disposition::Unknown;
foreach (['reversal', 'generation', 'reservation', 'owner', 'pause', 'payload', 'cancel'] as $change) {
    $op = $new('changed-'.$change.'.com');
    $preflight->during = static function ($claimed, $domain) use ($change, $bindings, $user) {
        match ($change) {
            'reversal' => InvoiceTransaction::create(['invoice_id' => $claimed->invoice_id, 'amount' => '-0.01', 'fee' => '0.00', 'status' => 'succeeded']),
            'generation' => DB::table('domains_domains')->where('id', $domain->id)->update(['authority_generation' => 2]),
            'reservation' => DB::table('domains_domains')->where('id', $domain->id)->update(['reservation_name' => null]),
            'owner' => DB::table('services')->where('id', $domain->service_id)->update(['user_id' => $user->id + 999]),
            'pause' => $bindings->setProcessing($domain->server_id, false),
            'payload' => $claimed->fresh()->update(['payload' => $claimed->payload + ['changed' => true]]),
            'cancel' => App\Models\ServiceCancellation::create(['service_id' => $domain->service_id, 'reason' => 'Synthetic', 'type' => 'end_of_period']),
        };
    };
    $result = $assertNoSend($op); same('absent', $result->attempt['seal'], 'Authority changes block final seal');
    $preflight->during = null; if ($change === 'pause') { $bindings->setProcessing($op->server_id, true); }
}
foreach (['capability', 'premium', 'short', 'old'] as $change) {
    $op = $new('invalid-'.$change.'.com');
    if ($change === 'capability') { $preflight->supported = false; } elseif ($change === 'premium') { $preflight->premium = null; } elseif ($change === 'short') { $preflight->freshSeconds = 24; } else { $preflight->ageSeconds = 1; }
    $assertNoSend($op); $preflight->supported = true; $preflight->premium = false; $preflight->freshSeconds = 120; $preflight->ageSeconds = 0;
}
$job = new ExecuteOperation($op->id); same(1, $job->tries, 'One queue attempt'); same(300, $job->timeout, 'Ten bounded requests and worker margin');
same($op->id, unserialize(serialize($job))->operationId, 'Readonly job identifier survives queue serialization');
same(360, ExecuteOperation::LEASE_SECONDS, 'Lease outlives worker by cleanup margin'); expect($job->failOnTimeout, 'Worker timeout fails job');
commerceReject(fn () => DB::transaction(fn () => $engine->execute($op->id)));
echo "Operation financial claims and seal checks passed\n";
$nativeSuccessful = true;
