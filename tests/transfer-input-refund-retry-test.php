<?php
// Adapted from Enom 444f31c transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-input-fixture.php';
use App\Models\{Credit, InvoiceTransaction, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, ContactRecovery, ContactSetup, InvoiceBinding, NativeDomainGuard, HolderPricing, PricingRule, Rdap, Refunds, TransferInputs, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$user->credits()->create(['currency_code'=>'USD','amount'=>'0.00']);
$clock->instant=$now;
// Credit failure keeps action-specific rejection; the existing bounded26 retry completes once.
$retry=$new(placeholderComFixtureName('transfer-credit-retry-placeholder.com')); $inputs->paymentAccepted($retry->id); $clock->instant=$now->modify('+30 days');
DB::unprepared("CREATE TRIGGER fail_transfer_credit BEFORE UPDATE ON credits BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
try{$inputs->expireMissingCode($retry->id);expect(false,'Credit fails');}catch(Illuminate\Database\QueryException){}
DB::unprepared('DROP TRIGGER fail_transfer_credit');
same('transfer_rejected',$retry->domain->fresh()->status,'Rejected lifecycle survives credit failure');same('cancelled',Service::find($retry->service_id)->status,'Service closes with timeout disposition');
same('rejected',$retry->fresh()->state,'Disposition survives failed credit');
// Use the fixture's fresh catalogue instant for competing checkout, isolating reservation authority.
$clock->instant=$now; $counts=[Service::count(),Domain::count(),Operation::count(),App\Models\Invoice::count()];
foreach([$user,$other]as$buyer){$cart=commerceCart($buyer);commerceAdd($cart,$product,$retry->domain->domain);commerceCheckout($buyer,$cart);
    same($counts,[Service::count(),Domain::count(),Operation::count(),App\Models\Invoice::count()],'Pending credit blocks same/foreign owner checkout without native writes');}
same($retry->domain->domain,$retry->domain->fresh()->reservation_name,'Failed credit retains retry identity');
$clock->instant=$now->modify('+30 days'); same(1,$refunds->retryPending(),'Existing refund retry includes transfer'); same(0,$refunds->retryPending(),'Retry once');
$clock->instant=$now;$cart=commerceCart($other);commerceAdd($cart,$product,$retry->domain->domain);commerceCheckout($other,$cart);
same(null,$retry->domain->fresh()->reservation_name,'Completed credit allows replacement');same(2,Domain::where('domain',$retry->domain->domain)->count(),'New owner checkout succeeds after credit');
expect(isset($retry->fresh()->funding['refund']),'Replacement retains original once-credit evidence');
$clock->instant=$now;
$nativeSuccessful=true;echo "Native transfer input scenario passed\n";
