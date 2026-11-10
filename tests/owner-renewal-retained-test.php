<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
// Real native checkout, controller rendering and locked financial rows need the installed fixture.
$registrationNames=['owner-renew-placeholder.com','owner-renew-late-placeholder.com'];
require __DIR__.'/Support/owner-renewal-fixture.php';
$setting('domains_manual_renewals_enabled',true);$setting('domains_billing_enabled',true);
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');
// Signed C2 offers annual renewal until an exact longer-term quote is reviewed.
$response=$controller->renew($req($d,['years'=>'1']),$d->id);
$op=Operation::where('domain_id',$d->id)->where('action','renew')->sole();$invoice=Invoice::findOrFail($op->invoice_id);
same(route('invoices.show',$invoice),$response->getTargetUrl(),'Retained-cycle fixture starts with an issued owner invoice');
// Retained cycles remain visible under every gate that prevents creating a new invoice.
$cycleStates=['pending'=>['state'=>'awaiting_payment','result'=>['invoice'=>route('invoices.show',$invoice)],'heading'=>'Pay invoice'],
    'paid'=>['state'=>'pending','result'=>['status'=>'paid'],'heading'=>'Updates when the renewal completes'],
    'cancelled'=>['state'=>'awaiting_payment','result'=>['status'=>'cancelled'],'heading'=>'Invoice cancelled'],
    'held'=>['state'=>'held','result'=>['status'=>'review'],'heading'=>'Renewal being checked. No action needed.']];
foreach(['manual','stop','service','domain','cutoff','calendar'] as $gate){
    $rawDomain=$d->fresh()->getRawOriginal();$rawService=$d->service->fresh()->getRawOriginal();$savedTime=$clock->instant;
    if($gate==='manual'){$setting('domains_manual_renewals_enabled',false);}
    if($gate==='stop'){$d->update(['renewal_policy'=>[...$d->renewal_policy,'renewals_stopped'=>true]]);}
    if($gate==='service'){$d->service->fresh()->update(['status'=>'suspended']);}
    if($gate==='domain'){$d->update(['status'=>'needs_review']);}
    if($gate==='cutoff'){$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');}
    if($gate==='calendar'){$server->settings()->updateOrCreate(['key'=>'domains_due_days'],['value'=>'invalid','type'=>'string']);}
    foreach($cycleStates as $state=>$expected){
        DB::table('invoices')->where('id',$invoice->id)->update(['status'=>$state==='held'?'pending':$state]);
        DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$expected['state']]);$unchanged=$ledger();
        $expired=$gate==='cutoff'&&in_array($state,['pending','held'],true);
        same($expired?['status'=>'cancelled']:$expected['result'],$owners->present($d->fresh())->renewNow,'Retained '.$state.' survives '.$gate);
        $html=$page($d);same(1,substr_count($html,'>'.($expired?'Invoice cancelled':$expected['heading']).'<'),'One retained heading: '.$state.' '.$gate);
        expect(!str_contains($html,'Create invoice'),'Retained cycle has no new request: '.$state.' '.$gate);
        same($unchanged,$ledger(),'Retained presentation is read-only: '.$state.' '.$gate);
    }
    DB::table('domains_domains')->where('id',$d->id)->update($rawDomain);DB::table('services')->where('id',$d->service_id)->update($rawService);
    $clock->instant=$savedTime;$setting('domains_manual_renewals_enabled',true);$server->settings()->where('key','domains_due_days')->delete();
}
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'awaiting_payment']);

// Raw retained rows model inconsistent stored data; guarded model edits remain forbidden.
$rawInvoice=$invoice->fresh()->getRawOriginal();$rawItem=$invoice->items()->sole()->getRawOriginal();$rawOp=$op->fresh()->getRawOriginal();
foreach(['missing_item','membership','currency','amount','due','missing_due','base','missing_base','expected','missing_expected','service','server','account','generation','owner'] as $case){
    if($case==='missing_item'){DB::table('invoice_items')->where('id',$rawItem['id'])->delete();}
    if($case==='membership'){DB::table('invoice_items')->insert([...$rawItem,'id'=>999999]);}
    if($case==='currency'){DB::table('invoices')->where('id',$invoice->id)->update(['currency_code'=>'EUR']);}
    if($case==='amount'){DB::table('invoice_items')->where('id',$rawItem['id'])->update(['price'=>'0.01']);}
    if($case==='due'){DB::table('invoices')->where('id',$invoice->id)->update(['due_at'=>'2027-10-12']);}
    if($case==='missing_due'){DB::table('invoices')->where('id',$invoice->id)->update(['due_at'=>null]);}
    if($case==='missing_base'){DB::table('domains_operations')->where('id',$op->id)->update(['base_expires_at'=>null]);}
    if($case==='missing_expected'){DB::table('domains_operations')->where('id',$op->id)->update(['expected_expires_at'=>null]);}
    if($case==='base'){DB::table('domains_operations')->where('id',$op->id)->update(['base_expires_at'=>'2027-10-19']);}
    if($case==='expected'){DB::table('domains_operations')->where('id',$op->id)->update(['expected_expires_at'=>'2030-10-18']);}
    if(in_array($case,['service','server','account','generation'],true)){
        $field=['service'=>'service_id','server'=>'server_id','account'=>'account_fingerprint','generation'=>'authority_generation'][$case];
        DB::table('domains_operations')->where('id',$op->id)->update([$field=>$case==='account'?str_repeat('0',64):999999]);
    }
    if($case==='owner'){DB::table('invoices')->where('id',$invoice->id)->update(['user_id'=>$other->id]);}
    $unchanged=$ledger();same(['status'=>'review'],$owners->present($d->fresh())->renewNow,'Invalid retained invoice shows review: '.$case);
    $html=$page($d);expect(str_contains($html,'Renewal being checked')&&!str_contains($html,'Pay invoice')&&!str_contains($html,'Create invoice'),'Invalid retained page offers no billing action: '.$case);
    $refused($d,['years'=>'2'],'This renewal is under review. Contact support if you need an update on this renewal.');same($unchanged,$ledger(),'Invalid retained data creates no debt: '.$case);
    DB::table('invoice_items')->where('id',999999)->delete();DB::table('invoice_items')->updateOrInsert(['id'=>$rawItem['id']],$rawItem);
    DB::table('invoices')->where('id',$invoice->id)->update($rawInvoice);DB::table('domains_operations')->where('id',$op->id)->update($rawOp);
}
same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Restored retained invoice is payable');
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
    // A cancelled invoice whose renewal reached the registrar stays under review until proven unsent.
    $expected=in_array($case,['retireable','cancelled_native'],true)?'cancelled':'review';$unchanged=$ledger();
    foreach([1,2] as $pass){
        $label=$case.':'.$pass;$html=$page($d);
        $expiryExpected[$label]=[['status'=>$expected],$expected==='cancelled'?1:0,$expected==='review'?1:0];
        $expiryResults[$label]=[$bounded(fn()=>$owners->present($d->fresh())->renewNow),substr_count($html,'>Invoice cancelled<'),substr_count($html,'>Renewal being checked.<')];
        expect(!str_contains($html,'Pay invoice')&&!str_contains($html,'Create invoice'),'Expired '.$label.' offers no billing action');
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


echo "Owner retained renewal gates, invalid rows and expiry evidence passed.\n";$nativeSuccessful=true;
