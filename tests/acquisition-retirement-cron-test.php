<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview};
use Paymenter\Extensions\Servers\Domains\Registration\Checkout\UnpaidAcquisitionProof;
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
// Native full cron, shared hosting invoice, no invoice model events and all-null stock.
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('unpaid-mixed-placeholder.com'));$hosting=commerceHosting();
$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart);
$mixed=Domain::where('domain','unpaid-mixed-placeholder.com')->sole()->operations()->sole();
$shared=App\Models\Invoice::findOrFail($mixed->invoice_id);same(2,$shared->items()->count(),'Shared native invoice fixture');
commerceReject(fn()=>$retirement->retire($mixed->domain_id,$staff,$ticket($mixed),'Shared pending invoice refuses'));same('pending',$shared->fresh()->status,'Staff shared-invoice refusal changes no invoice');same('pending',$mixed->domain->service->fresh()->status,'Staff shared-invoice refusal changes no service');
$soloCron=$new(placeholderComFixtureName('unpaid-solo-cron-placeholder.com'),paid:false);DB::table('services')->where('id',$soloCron->service_id)->update(['created_at'=>$clock->now()->modify('-8 days')]);
DB::table('services')->whereIn('id',$shared->items()->pluck('reference_id'))->update(['created_at'=>$clock->now()->modify('-8 days')]);
// Hide other intentionally adversarial fixtures from this real upstream cron qualification.
DB::table('services')->whereNotIn('id',[$mixed->service_id,$soloCron->service_id])->where('status','pending')->update(['created_at'=>$clock->now()]);
$invoiceEvents=0;App\Models\Invoice::updated(function()use(&$invoiceEvents):void {$invoiceEvents++;});
Illuminate\Support\Facades\Artisan::call('app:cron-job');
same('cancelled',$shared->fresh()->status,'Vanilla cron cancels the whole shared invoice');same('cancelled',$mixed->domain->service->fresh()->status,'Vanilla cron cancels managed service');
same(0,$invoiceEvents,'Vanilla cron bulk cancellation emits no Invoice update event');
same('cancelled',App\Models\Invoice::find($soloCron->invoice_id)->status,'Vanilla cron cancels solo invoice');same('cancelled',$soloCron->domain->service->fresh()->status,'Vanilla cron cancels solo service');$retirement->inspect($soloCron->domain_id);same(null,$soloCron->domain->fresh()->reservation_name,'Solo cron retirement releases name');
$retirement->inspect($mixed->domain_id);same(null,$mixed->domain->fresh()->reservation_name,'Sweep releases shared-invoice unpaid acquisition');same(null,$product->fresh()->stock,'Cron never replenishes managed stock');
// Retained reviews cannot starve an obsolete review beyond the first 25 domains.
// One real checkout supplies the review fixtures; copying its native rows avoids 24 repeated Livewire renders.
// Each review still has its own order, service, invoice, line, domain and frozen checkout identity.
$seed=$new(placeholderComFixtureName('review-fair-0-placeholder.com'),paid:false);
$copy=static function(Illuminate\Database\Eloquent\Model $source,array $changes=[]):Illuminate\Database\Eloquent\Model {
    $row=$source->replicate();
    $row->forceFill(['created_at'=>$source->created_at,'updated_at'=>$source->updated_at,...$changes]);
    $id=DB::table($row->getTable())->insertGetId($row->getAttributes());
    return $row->newQuery()->findOrFail($id);
};
$retained=[$seed];$seedDomain=$seed->domain;$seedService=$seedDomain->service;
$seedInvoice=App\Models\Invoice::findOrFail($seed->invoice_id);
$seedItem=App\Models\InvoiceItem::findOrFail($seed->invoice_item_id);
for($i=1;$i<25;$i++){
    $name=placeholderComFixtureName('review-fair-'.$i.'-placeholder.com');
    $order=$copy($seedService->order);
    $service=$copy($seedService,['order_id'=>$order->id,'label'=>$name]);
    foreach($seedService->properties as $property){
        $copy($property,['model_id'=>$service->id,...($property->key==='domain'?['value'=>$name]:[])]);
    }
    $invoice=$copy($seedInvoice,['number'=>null]);
    $item=$copy($seedItem,['invoice_id'=>$invoice->id,'reference_id'=>$service->id,
        'description'=>str_replace($seedDomain->domain,$name,$seedItem->description)]);
    $checkout=[...$seed->policy['checkout'],'name'=>$name,'order_id'=>$order->id,'service_id'=>$service->id,
        'invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id];
    $domain=$copy($seedDomain,['domain'=>$name,'sld'=>substr($name,0,-4),'reservation_name'=>$name,'service_id'=>$service->id,
        'snapshot'=>[...$seedDomain->snapshot,'checkout'=>$checkout]]);
    $retained[]=$copy($seed,['domain_id'=>$domain->id,'service_id'=>$service->id,'invoice_id'=>$invoice->id,
        'invoice_item_id'=>$item->id,'operation_key'=>'invoice-item:'.$item->id,'policy'=>[...$seed->policy,'checkout'=>$checkout]]);
}
foreach($retained as $op){
    // Invalid copies must fail here instead of surviving because the review sweep catches identity refusals.
    same($op->id,DB::transaction(fn()=>UnpaidAcquisitionProof::require(HoldingLocks::take($op->domain,foreignInvoices:true))->id),'Review fixture retains valid unpaid acquisition proof');
    \Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($op->domain,'acquisition_retirement_review',$clock->now()->getTimestamp());
}
$obsolete=$new(placeholderComFixtureName('review-fair-obsolete-placeholder.com'));
\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($obsolete->domain,'acquisition_retirement_review',$clock->now()->getTimestamp());
$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$obsolete->server_id)->sole();
$open=static fn($op)=>DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','acquisition_retirement_review')->whereNull('resolved_at')->exists();
$money=$nativeMoney();
(new AcquisitionRetirement($clock))->expireUnpaid($account);
expect($open($obsolete),'The first sweep respects the 25-domain review limit');
(new AcquisitionRetirement($clock))->expireUnpaid($account);
expect(!$open($obsolete),'A later sweep reaches the obsolete review behind 25 retained reviews');
foreach($retained as $op){expect($open($op),'Unproved retirement keeps its review');}
same($money,$nativeMoney(),'Review cleanup leaves money and jobs unchanged');
// A new service instance must wrap and revisit a previously retained review.
$first=$retained[0];DB::table('invoices')->where('id',$first->invoice_id)->update(['status'=>'paid']);
App\Models\InvoiceTransaction::create(['invoice_id'=>$first->invoice_id,'amount'=>$first->amount,'fee'=>'0.00','status'=>'succeeded']);
$paidMoney=$nativeMoney();(new AcquisitionRetirement($clock))->expireUnpaid($account);
expect(!$open($first),'The durable review cursor wraps to the beginning');
same($paidMoney,$nativeMoney(),'Review sweeps leave money and jobs unchanged');
echo "Unpaid acquisition retirement case passed.\n";$nativeSuccessful=true;
