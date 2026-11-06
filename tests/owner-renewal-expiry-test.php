<?php
// Native owner renewal matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerRenewalController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, OwnedDomains, RenewalCycles, RuntimeIneligible, RuntimeReason};
$ownerRenewalDomains=2;
require __DIR__.'/Support/owner-renewal.php';
require __DIR__.'/Support/owner-renewal-issued.php';
// Expiry alone cannot retire an in-flight payment, collection or registrar operation.
$expiryResults=[];$expiryExpected=[];$savedTime=$clock->instant;
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');
foreach(['processing','auto_pay_uncertain','pending','uncertain','retireable','cancelled_native','cancelled_native_uncertain'] as $case){
    $paymentId=null;
    if($case==='processing'){$paymentId=DB::table('invoice_transactions')->insertGetId(['invoice_id'=>$invoice->id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing','created_at'=>now(),'updated_at'=>now()]);}
    if($case==='auto_pay_uncertain'){
        $funding=$op->fresh()->funding;
        same('skipped',$funding['auto_pay']['status'],'Manual renewal starts with definite non-collection');
        $op->funding=[...$funding,'auto_pay'=>[...$funding['auto_pay'],'status'=>'uncertain','reason'=>null]];
        DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>$op->getAttributes()['funding']]);
        same('uncertain',$op->fresh()->funding['auto_pay']['status'],'Uncertain collection evidence is stored');
    }
    if(in_array($case,['pending','uncertain','cancelled_native_uncertain'],true)){DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$case==='cancelled_native_uncertain'?'uncertain':$case]);}
    if(str_starts_with($case,'cancelled_native')){DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']);}
    $expected=$case==='retireable'||str_starts_with($case,'cancelled_native')?'cancelled':'review';$unchanged=$ledger();
    foreach([1,2] as $pass){
        $label=$case.':'.$pass;$html=$page($d);
        $expiryExpected[$label]=[['status'=>$expected],$expected==='cancelled'?1:0,$expected==='review'?1:0];
        $expiryResults[$label]=[$bounded(fn()=>$owners->present($d->fresh())->renewNow),substr_count($html,'>Invoice cancelled<'),substr_count($html,'>Renewal being checked. No action needed.<')];
        expect(!str_contains($html,'Pay invoice')&&!str_contains($html,'Continue to payment'),'Expired '.$label.' offers no billing action');
        same($unchanged,$ledger(),'Repeated expired '.$label.' presentation preserves all evidence');
    }
    if($paymentId!==null){DB::table('invoice_transactions')->where('id',$paymentId)->delete();}
    DB::table('invoices')->where('id',$invoice->id)->update($rawInvoice);DB::table('domains_operations')->where('id',$op->id)->update($rawOp);
}
$clock->instant=$savedTime;
same(14,count($expiryResults),'Every expired blocker and control is read twice');
same($expiryExpected,$expiryResults,'Expired renewal status requires complete unpaid-retirement proof: '.json_encode($expiryResults,JSON_THROW_ON_ERROR));
foreach(['processing','succeeded','failed'] as $paymentState){
    $id=DB::table('invoice_transactions')->insertGetId(['invoice_id'=>$invoice->id,'amount'=>'1.00','fee'=>'0.00','status'=>$paymentState,'created_at'=>now(),'updated_at'=>now()]);
    $unchanged=$ledger();same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Pending invoice remains payable with partial or unsettled payment: '.$paymentState);
    same($unchanged,$ledger(),'Payment presentation preserves evidence: '.$paymentState);DB::table('invoice_transactions')->where('id',$id)->delete();
}
foreach(['queued','running','pending','uncertain'] as $operationState){
    DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$operationState]);
    same(['status'=>'review'],$owners->present($d->fresh())->renewNow,'Pending debt without payment-wait state shows review: '.$operationState);
}
DB::table('domains_operations')->where('id',$op->id)->update($rawOp);
$rawDomain=$d->fresh()->getRawOriginal();DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>null]);
same(['status'=>'review'],$bounded(fn()=>$owners->present($d->fresh())->renewNow),'Retained cycle with missing expiry shows review');DB::table('domains_domains')->where('id',$d->id)->update($rawDomain);
$rawLate=$late->fresh()->getRawOriginal();DB::table('domains_domains')->where('id',$late->id)->update(['provider_expires_at'=>null]);
same([],$bounded(fn()=>$owners->present($late->fresh())->renewNow),'Missing expiry without a renewal has no cycle');DB::table('domains_domains')->where('id',$late->id)->update($rawLate);


// Auto-renew off: billing refuses manual requests for a stopped renewal, so the page offers none; turning it on restores the form.
$policy=$late->fresh()->renewal_policy??[];$late->update(['renewal_policy'=>[...$policy,'renewals_stopped'=>true]]);$dto=$owners->present($late->fresh());
same(['Off',[]],[$dto->renewal,$dto->renewNow],'Auto-renew off hides the request billing refuses');
$html=$page($late);
same(0,storefrontDom($html)->query('//form[@data-renewal-form] | //a[@data-dialog-open and contains(@href,"?edit=renew#edit-renew")]')->length,'Auto-renew off page shows no renewal form or opening action');
$refused($late,['years'=>'1'],OwnerRenewalController::UNAVAILABLE);same($issued,$ledger(),'Auto-renew off request creates no invoice');
$late->update(['renewal_policy'=>$policy]);$dto=$owners->present($late->fresh());
same(['On',[1]],[$dto->renewal,$dto->renewNow['terms']??null],'Auto-renew on offers the request again');

// Outside the calendar: hidden on the expiry date and refused with plain copy.
$clock->instant=new DateTimeImmutable('2027-10-17T23:00:00Z');expect($owners->present($late->fresh())->renewNow!==[],'Last request day still offers renewal');
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');same([],$owners->present($late->fresh())->renewNow,'Expiry date hides the request');
$refused($late,['years'=>'1'],'This domain is too close to its expiry date to renew online. Contact support to renew it.');
same($issued,$ledger(),'Outside calendar creates no invoice');
same(1,Invoice::whereIn('id',Operation::where('action','renew')->pluck('invoice_id'))->count(),'Exactly one renewal invoice issued');


foreach([Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class,App\Jobs\Server\CreateJob::class] as $job){Queue::assertNotPushed($job);}
$nativeSuccessful=true;echo "owner-renewal-expiry-test.php: passed.\n";
