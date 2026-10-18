<?php
// Adapted from Enom 444f31c transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Credit, InvoiceTransaction, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, ContactRecovery, ContactSetup, InvoiceBinding, NativeDomainGuard, HolderPricing, PricingRule, Rdap, Refunds, TransferInputs, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture(true)); $clock = new FakeClock($now);
Carbon\Carbon::setTestNow($now); Carbon\CarbonImmutable::setTestNow($now);
$server = App\Models\Server::find($tlds[0]->server_id);
$server->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);
$rdap = new Rdap(fn () => throw new RuntimeException('Synthetic offline lookup'));
$eligibility = new CheckoutEligibility($bindings,$clock,new TransferPrecheck($rdap));
$adapter = new CartAdmission($native,$sellers,$eligibility,$clock); $adapter->boot();
(new InvoiceBinding($adapter,$native,$prices,$clock))->boot();
(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
$user = commerceUser(); $other = commerceUser(); $product = $native->product($tlds[0],'transfer');
$refunds = new Refunds($clock); $inputs = new TransferInputs($clock,$refunds);
$new = static function (string $name, bool $paid=true) use($user,$product): Operation {
    $cart=commerceCart($user); commerceAdd($cart,$product,$name); commerceCheckout($user,$cart);
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    if($paid){ App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount); }
    return $op->fresh();
};
$post = static function ($actor, mixed $code, string $method='POST', bool $query=false): Request {
    $r=Request::create('/internal-transfer-input'.($query?'?transfer_code=forbidden':''),$method,['transfer_code'=>$code]);
    $r->setUserResolver(fn()=>$actor); return $r;
};
$unrelated=$new('transfer-unrelated.com',false);
App\Models\ServiceCancellation::create(['service_id'=>$unrelated->service_id,'type'=>'end_of_period','reason'=>'Unrelated service']);
$op=$new('transfer-input.com'); $inputs->paymentAccepted($op->id);
$paid=$op->fresh()->policy['paid_at']; same('awaiting_code',$op->fresh()->state,'Paid code missing');
$inputs->paymentAccepted($op->id); same($paid,$op->fresh()->policy['paid_at'],'Payment replay preserves cutoff');
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
foreach([['attempt'=>['execution'=>null]],['attempt'=>['seal'=>'sealed']],['attempt'=>['fallback'=>null]],['cost'=>['execution'=>null]],
    ['started_at'=>$now],['state'=>'uncertain'],['provider_order_id'=>'synthetic'],['funding'=>['claim'=>null]]] as $i=>$changes){
    $candidate=$new('transfer-refuse-'.$i.'.com');$inputs->paymentAccepted($candidate->id);if(isset($changes['cost'])){$changes['cost']=[...$candidate->cost,...$changes['cost']];}$candidate->update($changes);$clock->instant=$now->modify('+30 days');
    commerceReject(fn()=>$inputs->expireMissingCode($candidate->id));commerceReject(fn()=>$inputs->submitCode($post($user,'replacement'),$candidate->id));$clock->instant=$now;
}
// Changed transaction times cannot invent paid_at; native audit can recover the original transition.
$ambiguous=$new('transfer-time-unknown.com'); DB::table('invoice_transactions')->where('invoice_id',$ambiguous->invoice_id)->update(['updated_at'=>'2026-10-18 11:59:00']);
commerceReject(fn()=>$inputs->paymentAccepted($ambiguous->id));expect(!isset($ambiguous->fresh()->policy['paid_at']),'No fresh deadline on uncertainty');
DB::table('audits')->insert(['event'=>'updated','auditable_type'=>App\Models\Invoice::class,'auditable_id'=>$ambiguous->invoice_id,
    'old_values'=>'{"status":"pending"}','new_values'=>'{"status":"paid"}','created_at'=>$now,'updated_at'=>$now]);
$inputs->paymentAccepted($ambiguous->id);same($paid,$ambiguous->fresh()->policy['paid_at'],'Native paid audit recovery');
$partial=$new('transfer-partial.com',false); DB::table('invoices')->where('id',$partial->invoice_id)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$partial->invoice_id,'amount'=>'1.00','status'=>'succeeded']);commerceReject(fn()=>$inputs->paymentAccepted($partial->id));
$selfCancel=$new('transfer-self-cancel.com');App\Models\ServiceCancellation::create(['service_id'=>$selfCancel->service_id,'type'=>'end_of_period','reason'=>'Own service']);
commerceReject(fn()=>$inputs->paymentAccepted($selfCancel->id));$clock->instant=$now->modify('+30 days');commerceReject(fn()=>$inputs->expireMissingCode($selfCancel->id));$clock->instant=$now;
// Native datetime objects retain their offsets; raw audits use the configured database timezone.
$timezone=date_default_timezone_get();
foreach(['America/Los_Angeles','Asia/Kolkata']as$i=>$zone){
    $local=$new('transfer-zone-'.$i.'.com');$audit=$new('transfer-audit-zone-'.$i.'.com');
    date_default_timezone_set($zone);$stamp=$now->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i:s');
    // Represent these native timestamps as stored by this timezone, without reinterpreting the UTC catalogue fixture.
    DB::table('domains_operations')->whereIn('id',[$local->id,$audit->id])->update(['created_at'=>$stamp]);
    DB::table('invoice_transactions')->whereIn('invoice_id',[$local->invoice_id,$audit->invoice_id])->update(['created_at'=>$stamp,'updated_at'=>$stamp]);
    $local->refresh();expect($local->created_at->getOffset()!==0,'Non-UTC native timestamp fixture');
    $inputs->paymentAccepted($local->id);same($paid,$local->fresh()->policy['paid_at'],'Transaction paid time normalized to original UTC instant');
    DB::table('invoice_transactions')->where('invoice_id',$audit->invoice_id)->update(['updated_at'=>'2026-10-17 00:00:00']);
    DB::table('audits')->insert(['event'=>'updated','auditable_type'=>App\Models\Invoice::class,'auditable_id'=>$audit->invoice_id,
        'old_values'=>'{"status":"pending"}','new_values'=>'{"status":"paid"}','created_at'=>$stamp,'updated_at'=>$stamp]);
    $inputs->paymentAccepted($audit->id);same($paid,$audit->fresh()->policy['paid_at'],'Local audit time normalized to original UTC instant');
    date_default_timezone_set($timezone);
}
// Contact correction preserves independent transfer inputs and remains held.
$contacts=$new('transfer-contacts.com'); $inputs->paymentAccepted($contacts->id); $inputs->submitCode($post($user,'preserve'),$contacts->id);
$contacts->refresh()->update(['payload'=>[...$contacts->payload,'nameservers'=>['ns1.example.com','ns2.example.com']]]);
ContactRecovery::hold($contacts->id); $before=$contacts->fresh();
$user->update(['email'=>'transfer-owner@example.com']);
foreach(['address'=>'1 Example St','city'=>'Example','state'=>'CA','zip'=>'90001','country'=>'US','phone'=>'+1.2025550100'] as $k=>$v){$user->properties()->updateOrCreate(['key'=>$k],['value'=>$v]);}
ContactRecovery::replace($user,$contacts->id,'Owner corrected contact details'); $after=$contacts->fresh();
same($before->payload['epp'],$after->payload['epp'],'Contact correction preserves code');same($before->policy['paid_at'],$after->policy['paid_at'],'Contact correction preserves paid clock');
same($before->payload['registry'],$after->payload['registry'],'Contact correction preserves registry');same('held',$after->state,'Contact correction remains held');
same($before->payload['nameservers']??null,$after->payload['nameservers']??null,'Contact correction preserves nameservers');
$clock->instant=$now->modify('+30 days'); Carbon\Carbon::setTestNow($clock->instant);Carbon\CarbonImmutable::setTestNow($clock->instant);
ContactSetup::recoveryWrite(fn()=>$after->update(['safe_code'=>'contact_details_required']));
commerceReject(fn()=>ContactRecovery::replace($user,$after->id,'Late correction refused'));
echo "Transfer owner POST, encrypted inputs, cutoff, funding and timeout account credit passed.\n";$nativeSuccessful=true;
