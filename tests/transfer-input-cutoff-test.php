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
$op=$new(placeholderComFixtureName('transfer-saved-code-placeholder.com'));$inputs->paymentAccepted($op->id);$inputs->submitCode($post($user,'replacement'),$op->id);
$timeout=$new(placeholderComFixtureName('transfer-timeout-placeholder.com')); $inputs->paymentAccepted($timeout->id);
$clock->instant=$now->modify('+30 days -1 second'); same(null,$inputs->expireMissingCode($timeout->id),'No early timeout');
$inputs->submitCode($post($user,'last-second'),$op->id);
$clock->instant=$now->modify('+30 days');
commerceReject(fn()=>$inputs->submitCode($post($user,'too-late'),$timeout->id));
commerceReject(fn()=>$inputs->submitCode($post($user,'too-late'),$op->id));
same(null,$inputs->expireMissingCode($op->id),'Saved code prevents automatic credit');
$ledger=DB::table('invoice_transactions')->orderBy('id')->get()->toJson();
$result=$inputs->expireMissingCode($timeout->id); same(['amount'=>$timeout->amount,'currency'=>'USD'],$result,'Exact cutoff native account credit');
same('transfer_code_timeout',$timeout->fresh()->funding['refund']['reason'],'Action-specific credit audit reason');
same('transfer_code_timeout',$timeout->fresh()->safe_code,'Timeout disposition retained');
same('cancelled',Service::find($timeout->service_id)->status,'Unsent transfer service cancelled');
same('transfer_rejected',$timeout->domain->fresh()->status,'Transfer lifecycle rejected');
same($timeout->domain->domain,$timeout->domain->fresh()->reservation_name,'Reservation retained after refund');
same($ledger,DB::table('invoice_transactions')->orderBy('id')->get()->toJson(),'Native history preserved');
same(null,$inputs->expireMissingCode($timeout->id),'No repeated credit');
commerceReject(fn()=>$inputs->submitCode($post($user,'after-refund'),$timeout->id));
$nativeSuccessful=true;echo "Native transfer input scenario passed\n";
