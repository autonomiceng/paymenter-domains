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
// Cycle states: paid, review and cancelled invoices each show one status; a retired unpaid cycle offers a replacement request.
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');$cycle=static fn()=>$owners->present($d->fresh())->renewNow;
$shows=static function(array $texts,string $label)use($page,$d):void {$html=$page($d);
    foreach($texts as $text){expect(str_contains($html,$text),$label.' shows '.$text);}
    expect(!str_contains($html,'Continue to payment')&&!str_contains($html,'Pay invoice'),$label.' shows no form or link');};
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);same(['status'=>'paid'],$cycle(),'Paid cycle shows payment received');
$shows(['Paid','Updates when the renewal completes'],'Paid page');
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'held']);
same(['status'=>'review'],$cycle(),'Held cycle shows review');$shows(['Under review','>There’s nothing you need to do.<'],'Review page');
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'awaiting_payment']);DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']);$cancelled=$ledger();
same(['status'=>'cancelled'],$cycle(),'Cancelled invoice shows its status');$shows(['Invoice cancelled','Contact support'],'Cancelled page');
$refused($d,['years'=>'2'],OwnerRenewalController::CANCELLED);same($cancelled,$ledger(),'Cancelled cycle request creates no invoice');
(new RenewalCycles($bindings,$clock))->retireUnpaid($op->id);
DB::table('invoice_transactions')->insert(['invoice_id'=>$invoice->id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded','created_at'=>now(),'updated_at'=>now()]);
same(['status'=>'review'],$cycle(),'Retired cycle with payment history shows review');DB::table('invoice_transactions')->where('invoice_id',$invoice->id)->delete();
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');same(['status'=>'cancelled'],$cycle(),'Retired unpaid cycle remains visible after cutoff');
$shows(['Invoice cancelled'],'Retired cutoff page');
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');$setting('domains_manual_renewals_enabled',false);
same(['status'=>'cancelled'],$cycle(),'Retired unpaid cycle remains visible with requests off');$setting('domains_manual_renewals_enabled',true);
$server->settings()->updateOrCreate(['key'=>'domains_due_days'],['value'=>'invalid','type'=>'string']);same(['status'=>'cancelled'],$cycle(),'Retired unpaid cycle remains visible with invalid calendar');$server->settings()->where('key','domains_due_days')->delete();
same(range(1,9),$cycle()['terms']??null,'Retired unpaid cycle offers a replacement request');
$html=$page($d);expect(str_contains($html,'Continue to payment')&&!str_contains($html,'Invoice cancelled'),'Retired page shows the form, not the status');
// Replacement proof covers every debt, even beyond one page or under an unexpected key.
$copies=[];$copyInvoices=[];$copyItems=[];
$duplicate=static function(Operation $original,string $key,?string $base=null)use(&$copyInvoices,&$copyItems):Operation {
    $invoice=Invoice::findOrFail($original->invoice_id)->getRawOriginal();unset($invoice['id'],$invoice['number']);
    $invoiceId=DB::table('invoices')->insertGetId($invoice);$copyInvoices[]=$invoiceId;
    $item=\App\Models\InvoiceItem::findOrFail($original->invoice_item_id)->getRawOriginal();unset($item['id']);$item['invoice_id']=$invoiceId;
    $itemId=DB::table('invoice_items')->insertGetId($item);$copyItems[]=$itemId;
    $copy=$original->replicate();$copy->operation_key=$key;$copy->invoice_id=$invoiceId;$copy->invoice_item_id=$itemId;
    $attempt=$copy->attempt;$attempt['terminal']['operation_key']=$key;$attempt['terminal']['invoice_id']=$invoiceId;$copy->attempt=$attempt;
    if($base!==null){$copy->base_expires_at=$base;}$copy->save();return $copy;
};
for($i=1;$i<=101;$i++){
    $copy=$duplicate($op->fresh(),$i===101?'owner-cycle-unexpected':$op->operation_key.':replacement:'.$i);$copies[]=$copy->id;
}
foreach([1,2] as $pass){
    same(null,$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh())),'Every retired debt permits replacement: '.$pass);
    same(['status'=>'cancelled'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh(),true)),'Large retired cycle retains its status: '.$pass);
}
DB::table('domains_operations')->where('id',$copy->id)->update(['safe_code'=>'needs_review']);
same(['status'=>'review'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh())),'Unexpected-key debt on the second page blocks replacement');
DB::table('domains_operations')->where('id',$copy->id)->update(['safe_code'=>'renewal_cancelled_unpaid']);
DB::table('domains_operations')->where('id',$copy->id)->update(['base_expires_at'=>'not-a-date']);
same(['status'=>'review'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh())),'Malformed retained expiry still requires review');
same(['status'=>'review'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh(),true)),'Malformed retained expiry has no cancelled fallback');
DB::table('domains_operations')->where('id',$copy->id)->update(['base_expires_at'=>$op->getRawOriginal('base_expires_at')]);
DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'needs_review']);
same(['status'=>'review'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh())),'Old debt outside the newest 100 blocks replacement');
DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'renewal_cancelled_unpaid']);
DB::table('domains_operations')->whereIn('id',$copies)->delete();
DB::table('invoice_items')->whereIn('id',$copyItems)->delete();DB::table('invoices')->whereIn('id',$copyInvoices)->delete();
$response=$controller->renew($req($d,['years'=>'1']),$d->id);$replacement=Operation::where('domain_id',$d->id)->where('action','renew')->orderByDesc('id')->first();
$replacementUrl=route('invoices.show',Invoice::findOrFail($replacement->invoice_id));
same([$op->operation_key.':replacement:1',1,$replacementUrl],[$replacement->operation_key,(int)$replacement->term,$response->getTargetUrl()],'Replacement request lands on its own invoice');
same(['invoice'=>$replacementUrl],$cycle(),'Replacement cycle links its invoice');
$savedTime=$clock->instant;$rawReplacement=$replacement->getRawOriginal();
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');
DB::table('domains_operations')->where('id',$replacement->id)->update(['state'=>'uncertain']);$unchanged=$ledger();
foreach([1,2] as $pass){
    same(['status'=>'review'],$bounded(fn()=>$cycle()),'Expired replacement preserves uncertain outcome: '.$pass);
    same($unchanged,$ledger(),'Repeated replacement read preserves retired and current debt: '.$pass);
}
DB::table('domains_operations')->where('id',$replacement->id)->update($rawReplacement);$clock->instant=$savedTime;
same(['invoice'=>$replacementUrl],$cycle(),'Restored replacement still owns its invoice');
// Newer history for other cycles cannot hide the current invoice.
$copies=[];$copyInvoices=[];$copyItems=[];
for($i=0;$i<105;$i++){
    $copy=$duplicate($op->fresh(),'renew:'.$d->id.':g'.$d->authority_generation.':2026-10-18:replacement:'.$i,'2026-10-18');$copies[]=$copy->id;
}
foreach([strtoupper($replacement->operation_key),strtoupper($op->operation_key),$op->operation_key.'0:replacement:1'] as $key){
    $copy=$duplicate($op->fresh(),$key);$copies[]=$copy->id;
}
same(['invoice'=>$replacementUrl],$bounded(fn()=>$cycle()),'Large unrelated history preserves the current invoice');
$html=$page($d);expect(str_contains($html,'Pay invoice'),'Large history page still links the current invoice');
DB::table('domains_operations')->whereIn('id',$copies)->delete();
DB::table('invoice_items')->whereIn('id',$copyItems)->delete();DB::table('invoices')->whereIn('id',$copyInvoices)->delete();


foreach([Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class,App\Jobs\Server\CreateJob::class] as $job){Queue::assertNotPushed($job);}
$nativeSuccessful=true;echo "owner-renewal-history-test.php: passed.\n";
