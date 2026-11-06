<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-claims.php';
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, OperationPreflight};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, Capabilities, Capability, DispatchReadiness, Disposition, DriverResult, Evidence, Money, RequestContext, ResultReason, SendState, Support};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, DomainOperations, InvoiceBinding, OperationClaims};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$assertNoSend($new('unpaid.example.com', false)); $assertNoSend($new('setup.example.com', true, false));
$op = $new('ordinary.example.com'); $result = $engine->execute($op->id);
same('uncertain', $result->state, 'Unknown response holds obligation'); same('ordinary.example.com', $result->domain->reservation_name, 'Unknown holds name');
same('99.00', $writer->last->authorizedCost->amount, 'Known cost above paid retail is allowed: no dispatch margin floor');
same('possibly_sent', $result->attempt['execution']['send_state'], 'Normalized execution retained');
$assertNoSend($result, 'uncertain'); commerceReject(fn () => $engine->consume($writer->last, $writer->context, new \Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($writer->last->binding,catalogue: $GLOBALS['caps']??null)));
$forged = clone $writer->last; commerceReject(fn () => $engine->consume($forged, $writer->context, new \Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($forged->binding,catalogue: $GLOBALS['caps']??null)));
foreach (['execution', 'dispatch_attempted_at', 'claim_key', 'seal'] as $key) {
    $attempt = $result->attempt; unset($attempt[$key]); commerceReject(fn () => $result->fresh()->update(['attempt' => $attempt]));
}
$nativeSuccessful=true;echo "Native operation claim scenario passed.\n";
