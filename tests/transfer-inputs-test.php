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
$op=$new('transfer-input.com'); $inputs->paymentAccepted($op->id);
same('Transfer transfer-input.com: adds 1 year to the current expiry', App\Models\InvoiceItem::findOrFail($op->invoice_item_id)->description, 'Transfer promises an added year without guessing an unknown expiry');
$paid=$op->fresh()->policy['paid_at']; same('awaiting_code',$op->fresh()->state,'Paid code missing');
$nativeBeforeReplay=nativeFinancialSnapshot($op);$operationBeforeReplay=$op->fresh()->getRawOriginal();
$inputs->paymentAccepted($op->id); same($paid,$op->fresh()->policy['paid_at'],'Payment replay preserves cutoff');
same($operationBeforeReplay,$op->fresh()->getRawOriginal(),'Payment replay preserves operation bytes');
same($nativeBeforeReplay,nativeFinancialSnapshot($op),'Payment replay preserves native/financial state');
foreach(['',str_repeat('x',513),"bad\nvalue",[1],"bad\x7fvalue"] as $invalid){commerceReject(fn()=>$inputs->submitCode($post($user,$invalid),$op->id));}
foreach([[$other,'POST',false],[$user,'GET',false],[$user,'POST',true]] as [$actor,$method,$query]){
    commerceReject(fn()=>$inputs->submitCode($post($actor,'synthetic',$method,$query),$op->id));
}
$code='  Synthetic:Case-Sensitive  ';
same(null,$inputs->submitCode($post($user,$code),$op->id),'No code/model response');
same($code,$op->fresh()->payload['epp'],'Exact bytes preserved');
$raw=DB::table('domains_operations')->where('id',$op->id)->first();
expect(!str_contains($raw->payload,$code) && !str_contains(json_encode($op->fresh()),$code),'Encrypted and hidden');
expect(!DB::table('properties')->where('value',$code)->exists(),'No native property code');
ini_set('zend.exception_ignore_args','0'); $failSave=true; $private=bin2hex(random_bytes(24));
Operation::updating(static function(Operation $row)use($op,&$failSave){if($failSave && $row->id===$op->id){throw new RuntimeException('Synthetic store failure');}});
try{$inputs->submitCode($post($user,$private),$op->id);expect(false,'Injected persistence failure');}
catch(DomainException $error){
    $diagnostic=$error->getMessage().print_r($error->getTrace(),true).$error->getTraceAsString().json_encode($error->getTrace(),JSON_THROW_ON_ERROR);
    expect(!str_contains($diagnostic,$private),'Persistence error trace hides code');same(null,$error->getPrevious(),'No captured transaction cause');
    foreach($error->getTrace()as$frame){foreach($frame['args']??[]as$arg){if($arg instanceof Closure){expect(!array_key_exists('code',(new ReflectionFunction($arg))->getStaticVariables()),'No captured code closure');}}}
}finally{$failSave=false;ini_set('zend.exception_ignore_args','1');}
expect(TransferInputs::validCode(str_repeat('x',512)),'512-byte upper boundary');
$inputs->submitCode($post($user,'replacement'),$op->id);
commerceReject(fn()=>$op->fresh()->update(['payload'=>[...$op->fresh()->payload,'epp'=>'ordinary-edit']]));
commerceReject(fn()=>$op->fresh()->update(['policy'=>[...$op->fresh()->policy,'paid_at'=>$now->modify('+1 day')->format(DATE_ATOM)]]));
$unpaid=$new('transfer-unpaid.com',false); $inputs->submitCode($post($user,'prepaid'),$unpaid->id);
expect(!isset($unpaid->fresh()->policy['paid_at']),'Unpaid code never starts clock');
$timeout=$new('transfer-timeout.com'); $inputs->paymentAccepted($timeout->id);
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
$clock->instant=$now;
// Credit failure keeps action-specific rejection; the existing bounded26 retry completes once.
$retry=$new('transfer-credit-retry.com'); $inputs->paymentAccepted($retry->id); $clock->instant=$now->modify('+30 days');
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
echo "Transfer owner POST, encrypted inputs, cutoff, funding and timeout account credit passed.\n";
$nativeSuccessful=true;
