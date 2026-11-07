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
$op=$new(placeholderComFixtureName('transfer-input-placeholder.com')); $inputs->paymentAccepted($op->id);
same('Domain transfer: transfer-input-placeholder.com (1 year, added to the current expiry date)', App\Models\InvoiceItem::findOrFail($op->invoice_item_id)->description, 'Transfer promises an added year without guessing an unknown expiry');
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
$unpaid=$new(placeholderComFixtureName('transfer-unpaid-placeholder.com'),false); $inputs->submitCode($post($user,'prepaid'),$unpaid->id);
expect(!isset($unpaid->fresh()->policy['paid_at']),'Unpaid code never starts clock');
$nativeSuccessful=true;echo "Native transfer input scenario passed\n";
