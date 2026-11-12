<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
$invoicePresenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter(new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts),$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
AcquisitionRetirement::boot();
$lateNative=$new(placeholderComFixtureName('native-cancel-paid-placeholder.com'),paid:false);$lateBill=App\Models\Invoice::findOrFail($lateNative->invoice_id);
// Keep this real cancellation/late-payment case between native closure and ledger
// retirement, as its presenter assertion requires. Automatic retirement now normally
// completes during cancellation, so explicitly exercise its recoverable refusal.
$lateRetirementRefused=false;
Operation::updating(static function(Operation $op)use($lateNative,&$lateRetirementRefused):void {
    if((int)$op->id===(int)$lateNative->id&&$op->state==='rejected'){$lateRetirementRefused=true;throw new DomainException('Synthetic delayed retirement');}
});
$lateCancel=ServiceCancellation::create(['service_id'=>$lateNative->service_id,'type'=>'immediate','reason'=>'Cancel before payment']);
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($lateCancel));
expect($lateRetirementRefused,'The native cancellation attempted automatic retirement');
same('awaiting_payment',$lateNative->fresh()->state,'A refused retirement retains the native cancellation window');
$lateOutcome=null;
Illuminate\Support\Facades\Event::listen(App\Events\Invoice\Paid::class,static function($event)use($lateBill,$invoicePresenter,$user,&$lateOutcome):void {
    if($event->invoice->id===$lateBill->id){DB::afterCommit(static function()use($lateBill,$invoicePresenter,$user,&$lateOutcome):void {$lateOutcome=$invoicePresenter->invoiceOutcome($user,(int)$lateBill->id);});}
});
DB::transaction(fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$lateBill->id,'amount'=>$lateNative->amount,'fee'=>'0.00','status'=>'succeeded']));
same(['acquisition_cancelled_unpaid','pending',null,[$lateNative->domain->domain]],[$lateOutcome?->kind,$lateOutcome?->money,$lateOutcome?->amount,$lateOutcome?->domains],'Paid mail sees native acquisition cancellation before terminal retirement');
same('awaiting_payment',$lateNative->fresh()->state,'The money read does not finish refused retirement');
echo "Unpaid acquisition retirement case passed.\n";$nativeSuccessful=true;
