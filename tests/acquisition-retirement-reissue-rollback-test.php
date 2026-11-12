<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
AcquisitionRetirement::boot();
// A refused close rolls back the replacement invoice too; never return a payment link to it.
// Native rows and the retirement transaction are required to reproduce the rolled-back invoice ID.
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('unpaid-reissue-rollback-placeholder.com'));
$hosting=commerceHosting();$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart);
$refusedReissue=Domain::where('domain','unpaid-reissue-rollback-placeholder.com')->sole()->operations()->sole();
$invoiceCount=App\Models\Invoice::count();$refusedOnce=false;
Operation::updating(static function(Operation $op)use($refusedReissue,&$refusedOnce):void {
    if((int)$op->id===(int)$refusedReissue->id&&$op->state==='rejected'){$refusedOnce=true;throw new DomainException('Synthetic retirement refusal after reissue');}
});
$closed=$retirement->closeUnpaid((int)$refusedReissue->invoice_id,Paymenter\Extensions\Servers\Domains\Services\PayCheck::notice('expired'));
expect($refusedOnce,'The close refused after creating its replacement invoice');
same(null,$closed['invoice'],'A rolled-back replacement invoice never becomes a payment link');
same($invoiceCount,App\Models\Invoice::count(),'The refused close rolls back the replacement invoice');
same(['pending','awaiting_payment',$refusedReissue->domain->domain],[App\Models\Invoice::findOrFail($refusedReissue->invoice_id)->status,$refusedReissue->fresh()->state,$refusedReissue->domain->fresh()->reservation_name],'A refused close retains the unpaid order and reserved name');

$nativeSuccessful=true;
