<?php
// Enom 444f31c refunds refusal scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Credit, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, PricingRule, RefundPayment, RefundProof, Refunds, OperationTerminalProof};
$refunds=new Refunds($clock); $staff=commerceUser();
$role=Role::create(['name'=>'Refund evidence','permissions'=>['admin.domains.manage','admin.invoices.update']]); $staff->update(['role_id'=>$role->id]);
$op=$new('refund-presence.com'); $op->update(['state'=>'held','safe_code'=>'staff_review']);
$raw=$op->getRawOriginal(); $sraw=$op->domain->service->getRawOriginal(); $iraw=App\Models\Invoice::find($op->invoice_id)->getRawOriginal();
$itemraw=App\Models\InvoiceItem::find($op->invoice_item_id)->getRawOriginal();
$prove=static function($row) { return DB::transaction(static function()use($row){ $l=HoldingLocks::take($row->domain); RefundProof::requireUnsent($row,$l['domain']); return RefundPayment::requireCurrent($row,$l); }); };
same($op->amount,$prove($op)['amount'],'Positive exact native line before refusal matrix');
$cases=[];
foreach (['execution','provider_reference','dispatch_attempted_at','charge','order_candidates','evidence','registration','unknown_key'] as $key) {
    $cases['attempt '.$key]=static fn($row)=>$row->update(['attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent',$key=>null]]);
}
foreach (['actual','execution','refund','order_candidates'] as $key) { $cases['cost '.$key]=static fn($row)=>$row->update(['cost'=>[...($row->cost??[]),$key=>null]]); }
foreach (['claim','seal','grant','refund','refund_authorization'] as $key) { $cases['funding '.$key]=static fn($row)=>$row->update(['funding'=>[$key=>null]]); }
$cases['policy null dispatch']=static fn($row)=>$row->update(['policy'=>[...$row->policy,'dispatch_attempted_at'=>null]]);
$cases['provider order']=static fn($row)=>$row->update(['provider_order_id'=>'9003']);
$cases['lease']=static fn($row)=>$row->update(['lease_expires_at'=>now()->addMinute()]);
foreach(['pending','uncertain','succeeded','queued'] as $stateName){ $cases['state '.$stateName]=static fn($row)=>$row->update(['state'=>$stateName]); }
$cases['invoice currency']=static fn($row)=>DB::table('invoices')->where('id',$row->invoice_id)->update(['currency_code'=>'EUR']);
$cases['invoice owner']=static fn($row)=>DB::table('invoices')->where('id',$row->invoice_id)->update(['user_id'=>$staff->id]);
$cases['invoice pending']=static fn($row)=>DB::table('invoices')->where('id',$row->invoice_id)->update(['status'=>'pending']);
$cases['quantity']=static fn($row)=>DB::table('invoice_items')->where('id',$row->invoice_item_id)->update(['quantity'=>2]);
$cases['price']=static fn($row)=>DB::table('invoice_items')->where('id',$row->invoice_item_id)->update(['price'=>'99.00']);
$cases['reference']=static fn($row)=>DB::table('invoice_items')->where('id',$row->invoice_item_id)->update(['reference_id'=>999999]);
$cases['service owner']=static fn($row)=>DB::table('services')->where('id',$row->service_id)->update(['user_id'=>$staff->id]);
$cases['service currency']=static fn($row)=>DB::table('services')->where('id',$row->service_id)->update(['currency_code'=>'EUR']);
$cases['unreferenced registration']=static fn($row)=>DB::table('invoice_items')->where('id',$row->invoice_item_id)->update(['reference_type'=>null,'reference_id'=>null]);
foreach(['complimentary','unexpected'] as $basis){$cases['basis '.$basis]=static fn($row)=>DB::table('domains_operations')->where('id',$row->id)->update(['funding_basis'=>$basis]);}
$cases['nonbillable contact action']=static fn($row)=>DB::table('domains_operations')->where('id',$row->id)->update(['action'=>'contacts']);
$cases['foreign operation service']=static fn($row)=>DB::table('domains_operations')->where('id',$row->id)->update(['service_id'=>999999]);
$cases['zero amount']=static fn($row)=>DB::table('domains_operations')->where('id',$row->id)->update(['amount'=>'0.00']);
foreach($cases as $label=>$mutate){
    // Deliberate privileged fixture corruption; restore the exact issued bytes between isolated cases.
    DB::table('domains_operations')->where('id',$op->id)->update($raw); DB::table('invoices')->where('id',$op->invoice_id)->update($iraw);
    DB::table('invoice_items')->where('id',$op->invoice_item_id)->update($itemraw); DB::table('services')->where('id',$op->service_id)->update($sraw);
    $row=$op->fresh(); $mutate($row); $before=$row->fresh()->getRawOriginal(); $credit=Credit::sum('amount');
    commerceReject(fn()=>$refunds->refundHeld($staff,$row->id,'customer_cancelled'));
    same($before,$row->fresh()->getRawOriginal(),'No disposition from '.$label); same($credit,Credit::sum('amount'),'No credit from '.$label);
}
DB::table('domains_operations')->where('id',$op->id)->update($raw); DB::table('invoices')->where('id',$op->invoice_id)->update($iraw);
DB::table('invoice_items')->where('id',$op->invoice_item_id)->update($itemraw); DB::table('services')->where('id',$op->service_id)->update($sraw);
same($op->amount,$prove($op->fresh())['amount'],'Restored positive control after matrix');
$refunds->refundHeld($staff,$op->id,'customer_cancelled');
// Completed preseal local worker, normalized by the existing encoder, authorizes automatic retry credit.
$local=$new('refund-local-worker.com'); [$claimed]=$claims->claim($local->id); $claims->finish($claimed);
OperationTerminalProof::encode($claims,$local->id); expect($refunds->creditRejected($local->id)!==null,'Positive terminal-unsent automatic refund');
// A completed preseal worker held for lost seal authority can be disposed by fresh staff directly.
$invalid=$new('refund-seal-authority.com'); [$claim]=$claims->claim($invalid->id); $claims->finish($claim);
$invalid->refresh()->update(['safe_code'=>'seal_authority_invalid']);
expect($refunds->refundHeld($staff,$invalid->id,'authority_changed')!==null,'Held seal-authority-invalid positive credit');
// All sent/unknown/fallback evidence remains disqualifying regardless of held/candidate labels.
foreach(['accepted','null','absent'] as $mode){
    [$sent]=$prepare('refund-sent-'.$mode.'.com',$mode); $sent->update(['state'=>'held','safe_code'=>'staff_review',
        'policy'=>[...$sent->policy,'renewal_execution'=>['refundable_later'=>true]]]);
    commerceReject(fn()=>$refunds->refundHeld($staff,$sent->id,'customer_cancelled'));
}
echo "Independent negative proof and null/presence/route refusal matrix passed\n"; $nativeSuccessful=true;
