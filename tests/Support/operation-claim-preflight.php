<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/operation-claims.php';
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, OperationPreflight};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, Capabilities, Capability, DispatchReadiness, Disposition, DriverResult, Evidence, Money, RequestContext, ResultReason, SendState, Support};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, DomainOperations, InvoiceBinding, OperationClaims};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
foreach ($claimPreflightChanges as $change) {
    $op = $new('invalid-'.$change.'.example.com');
    if ($change === 'capability') { $preflight->supported = false; } elseif ($change === 'premium') { $preflight->premium = null; } elseif ($change === 'short') { $preflight->freshSeconds = 24; } else { $preflight->ageSeconds = 1; }
    $assertNoSend($op); $preflight->supported = true; $preflight->premium = false; $preflight->freshSeconds = 120; $preflight->ageSeconds = 0;
}
if($claimPreflightChanges===['short','old']){
$job = new ExecuteOperation($op->id); same(1, $job->tries, 'One queue attempt'); same(300, $job->timeout, 'Ten bounded requests and worker margin');
same($op->id, unserialize(serialize($job))->operationId, 'Readonly job identifier survives queue serialization');
same(360, ExecuteOperation::LEASE_SECONDS, 'Lease outlives worker by cleanup margin'); expect($job->failOnTimeout, 'Worker timeout fails job');
commerceReject(fn () => DB::transaction(fn () => $engine->execute($op->id)));
}
$nativeSuccessful=true;echo "Native operation claim scenario passed.\n";
