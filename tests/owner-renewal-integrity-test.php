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
$ownerRenewalDomains=1;
require __DIR__.'/Support/owner-renewal.php';
require __DIR__.'/Support/owner-renewal-issued.php';
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
    $html=$page($d);expect(str_contains($html,'>There’s nothing you need to do.<')&&!str_contains($html,'Pay invoice')&&!str_contains($html,'Continue to payment'),'Invalid retained page offers no billing action: '.$case);
    $refused($d,['years'=>'2'],'This renewal is under review. Contact support if you need an update on this renewal.');same($unchanged,$ledger(),'Invalid retained data creates no debt: '.$case);
    DB::table('invoice_items')->where('id',999999)->delete();DB::table('invoice_items')->updateOrInsert(['id'=>$rawItem['id']],$rawItem);
    DB::table('invoices')->where('id',$invoice->id)->update($rawInvoice);DB::table('domains_operations')->where('id',$op->id)->update($rawOp);
}
same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Restored retained invoice is payable');

foreach([Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class,App\Jobs\Server\CreateJob::class] as $job){Queue::assertNotPushed($job);}
$nativeSuccessful=true;echo "owner-renewal-integrity-test.php: passed.\n";
