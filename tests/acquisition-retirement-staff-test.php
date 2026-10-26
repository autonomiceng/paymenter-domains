<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
// Background closure evidence must not invalidate a review of unchanged authority and money.
$background=$new(placeholderComFixtureName('unpaid-background-placeholder.com'),paid:false);
DB::table('services')->where('id',$background->service_id)->update(['status'=>'cancelled']);
$key=$ticket($background);$money=$nativeMoney();$retirement->inspect($background->domain_id);
expect(isset($background->fresh()->policy['native_acquisition_closure']),'Inspection retained background closure evidence');
DB::table('domains_operations')->where('id',$background->id)->update(['updated_at'=>$clock->now()->modify('+1 minute')]);
same($money,$nativeMoney(),'Background closure retains payment history');
$retirement->retire($background->domain_id,$staff,$key,'Retire unchanged reviewed unpaid order');
same(null,$background->domain->fresh()->reservation_name,'Background closure preserves usable staff review');
// Every other policy field and all money/authority fields still invalidate the shared stamp.
$control=$new(placeholderComFixtureName('unpaid-stamp-control-placeholder.com'),paid:false);
$stamp=static fn()=>StaffHoldingReview::stamp(HoldingLocks::take($control->domain->fresh(),advisory:true));
$before=$stamp();$saved=$control->getRawOriginal();
foreach(['policy'=>[...$control->policy,'dispatch_attempted_at'=>'2026-10-19T00:00:00Z'],'funding'=>['payment'=>'changed'],'amount'=>'99.00','authority_generation'=>$control->authority_generation+1,'account_fingerprint'=>str_repeat('f',64),'state'=>'pending']as$field=>$value){
    $copy=$control->fresh();$copy->$field=$value;DB::table('domains_operations')->where('id',$control->id)->update([$field=>$copy->getAttributes()[$field]]);
    expect($before!==$stamp(),'Review stamp retains '.$field.' authority');
    DB::table('domains_operations')->where('id',$control->id)->update([$field=>$saved[$field]]);
}
// Staff action, fresh identity/permission/reason snapshot and retained audit.
$staffOp=$new(placeholderComFixtureName('unpaid-staff-placeholder.com'),paid:false);$key=$ticket($staffOp);
foreach([str_repeat('é',4),str_repeat('é',501),"valid\xff"] as $bad){commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$ticket($staffOp),$bad));}
commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,"Bad\nreason"));
$staffOp->domain->service->forceFill(['label'=>'Changed after review'])->save();commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,'Stale review refuses'));
$key=$ticket($staffOp);$staff->role->update(['permissions'=>['admin.domains.view']]);commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,'Revoked staff refuses'));$staff->role->update(['permissions'=>$permissions]);
$key=$ticket($staffOp);$other=staffUser($permissions);commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$other,$key,'Wrong actor refuses'));
$retirement->retire($staffOp->domain_id,$staff,$ticket($staffOp),str_repeat('🦦',500));
same(null,$staffOp->domain->fresh()->reservation_name,'Staff action releases unpaid name');same('cancelled',App\Models\Invoice::find($staffOp->invoice_id)->status,'Staff action closes sole pending debt');
$audit=$staffOp->fresh()->policy['unpaid_retirement'];same($staff->id,$audit['actor_id'],'Staff audit actor');same(str_repeat('🦦',500),$audit['reason'],'Staff audit reason');same('awaiting_payment',$audit['before']['operation'],'Staff audit prior state');
commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,'Replay cannot retire again'));
commerceReject(fn()=>$staffOp->fresh()->update(['policy'=>array_diff_key($staffOp->fresh()->policy,['unpaid_retirement'=>true])]));
// Released names stay released after money arrives; terminal work remains nonspendable.
App\Models\InvoiceTransaction::create(['invoice_id'=>$staffOp->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);
$retirement->inspect($staffOp->domain_id);same('rejected',$staffOp->fresh()->state,'Late money never reopens work');same(null,$staffOp->domain->fresh()->reservation_name,'Late money never steals back a reservation');
same(1,DB::table('domains_obligation_alerts')->where('domain_id',$staffOp->domain_id)->where('code','acquisition_late_money')->count(),'Late money durable review');
$countTransactions=false;$lateLocks=0;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionBeginning::class,static function()use(&$countTransactions,&$lateLocks){if($countTransactions){$lateLocks++;}});
$countTransactions=true;$retirement->inspect($staffOp->domain_id);$retirement->inspect($staffOp->domain_id);$countTransactions=false;
same(0,$lateLocks,'Repeated late-money identity avoids native locking transactions');
foreach(['status'=>'succeeded','amount'=>'2.00'] as $field=>$value){
    DB::table('invoice_transactions')->where('invoice_id',$staffOp->invoice_id)->update([$field=>$value]);
    $lateLocks=0;$countTransactions=true;$retirement->inspect($staffOp->domain_id);$countTransactions=false;
    expect($lateLocks>0,'Changed transaction '.$field.' takes locked late-money recheck');
}
same(3,DB::table('domains_obligation_alerts')->where('domain_id',$staffOp->domain_id)->where('code','acquisition_late_money')->count(),'Payment status and amount each retain a distinct alert');
DB::table('domains_obligation_alerts')->where('domain_id',$staffOp->domain_id)->update(['resolved_at'=>time()]);
$lateLocks=0;$countTransactions=true;$retirement->inspect($staffOp->domain_id);$countTransactions=false;
expect($lateLocks>0,'Resolved late-money alert receives a locked recheck');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$staffOp->domain_id)->whereNull('resolved_at')->count(),'Same reviewed late-money identity stays resolved after locked recheck');
DB::table('invoices')->where('id',$staffOp->invoice_id)->update(['status'=>'paid']);
DB::transaction(fn()=>(new App\Services\Invoice\ProcessPaidInvoiceService)->handle(App\Models\Invoice::findOrFail($staffOp->invoice_id)));same('active',$staffOp->domain->service->fresh()->status,'Late native paid activation never throws');same(null,$staffOp->domain->service->fresh()->expires_at,'Late native paid activation keeps null expiry');$retirement->inspect($staffOp->domain_id);same('rejected',$staffOp->fresh()->state,'Late activation never reopens work');
same(4,DB::table('domains_obligation_alerts')->where('domain_id',$staffOp->domain_id)->where('code','acquisition_late_money')->count(),'Native service and invoice status changes retain new late-money evidence');
$lateLocks=0;$countTransactions=true;$retirement->inspect($staffOp->domain_id);$retirement->inspect($staffOp->domain_id);$countTransactions=false;
same(0,$lateLocks,'Repeated activated-service identity also skips locking');
// Match the installed after-commit wake used by the original modal scenarios.
AcquisitionRetirement::boot();
// Real staff modal success and refusal use the same locked authority.
Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();staffPanel();Auth::login($staff);
$pageClass=Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class;
$modalOp=$new(placeholderComFixtureName('unpaid-modal-placeholder.com'),paid:false);Auth::login($staff);
$page=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$modalOp->domain_id])->mountAction('retire_unpaid')->assertActionMounted('retire_unpaid');
$beforeInvalidReason=$modalOp->fresh()->getRawOriginal();
$page->setActionData(['reason'=>"Reviewed\u{2028}closure"])->callMountedAction()->assertHasActionErrors(['reason'=>'regex']);
same($beforeInvalidReason,$modalOp->fresh()->getRawOriginal(),'Closure reason field error changes no order');
$page->setActionData(['reason'=>'Retire from real staff modal'])->callMountedAction()->assertNotified('Order cancelled')->assertRedirect(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$modalOp->domain_id]));
Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$modalOp->domain_id])->assertActionDoesNotExist('retire_unpaid');same(null,$modalOp->domain->fresh()->reservation_name,'Real modal invokes retirement');
$paid=$new(placeholderComFixtureName('unpaid-refusal-placeholder.com'));Auth::login($staff);
$refusal=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$paid->domain_id])->mountAction('retire_unpaid');
$refusal->setActionData(['reason'=>'Paid modal must refuse'])->callMountedAction()->assertNotified("Couldn't cancel order");
same($paid->domain->domain,$paid->domain->fresh()->reservation_name,'Paid modal retains name');
// Both unpaid acquisition actions explain registrar evidence and other requests without blaming one cause.
foreach(['register','transfer'] as $action){foreach(['provider','sibling'] as $cause){
    $op=$new(placeholderComFixtureName('unpaid-modal-'.$action.'-'.$cause.'-placeholder.com'),paid:false,action:$action);
    if($cause==='provider'){$op->domain->update(['provider_order_id'=>'synthetic-provider']);}
    else{Operation::create(['domain_id'=>$op->domain_id,'service_id'=>$op->service_id,'server_id'=>$op->server_id,'registrar_key'=>$op->registrar_key,'account_fingerprint'=>$op->account_fingerprint,'authority_generation'=>1,'operation_key'=>'modal-other:'.$op->id,'action'=>'renew','term'=>1,'state'=>'queued','amount'=>'1.00','currency'=>'USD']);}
    $invoice=App\Models\Invoice::findOrFail($op->invoice_id)->getRawOriginal();$reservation=$op->domain->fresh()->reservation_name;
    Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$op->domain_id])->mountAction('retire_unpaid')->assertActionMounted('retire_unpaid')
        ->setActionData(['reason'=>'Review refused unpaid cancellation'])->callMountedAction()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't cancel order")
            ->body('Cannot cancel: review the order status, registrar requests and registrar evidence.'));
    same($reservation,$op->domain->fresh()->reservation_name,$action.' '.$cause.' keeps the reservation');
    same($invoice,App\Models\Invoice::findOrFail($op->invoice_id)->getRawOriginal(),$action.' '.$cause.' keeps the invoice');
}}
$viewOnly=staffUser(['admin.domains.view']);Livewire\Livewire::actingAs($viewOnly)->test($pageClass,['record'=>$paid->domain_id])->assertActionDoesNotExist('retire_unpaid');
echo "Unpaid retirement staff review, audit, late money and modals passed.\n";$nativeSuccessful=true;
