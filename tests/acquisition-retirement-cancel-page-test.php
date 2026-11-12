<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
// A cancelled service cannot start an unpaid order, so no page asks for payment.
// Nothing was paid, so the customer reads a plain cancel: Cancelled, no failure and no Contact support.
require dirname(__DIR__).'/Domains/routes/owned.php';require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
$open=$new(placeholderComFixtureName('unpaid-open-invoice-placeholder.com'),paid:false);
$owned=new Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new Paymenter\Extensions\Servers\Domains\Services\OwnerPorts);
$orderWords=static function()use($open,$owned):array {$page=$owned->present($open->domain->fresh());return [$page->order['state']??null,$page->status,$page->statusReason];};
same(['unpaid','Awaiting payment','Invoice not paid'],$orderWords(),'An open order on a live service offers payment');
DB::table('services')->where('id',$open->service_id)->update(['status'=>'cancelled']);
same(['cancelled','Cancelled','Order cancelled'],$orderWords(),'A cancelled service with an open invoice offers no payment and reads as a plain cancel');
$calm=$owned->present($open->domain->fresh());same(true,$calm->order['unpaid']??null,'The unpaid cancel is marked as nothing charged');
$note=Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($calm);same(['info','This order was cancelled. Nothing was charged.',null],[$note['tone']??null,$note['text']??null,$note['action']??null],'The page says nothing was charged and asks for nothing');
DB::table('services')->where('id',$open->service_id)->update(['status'=>'pending']);DB::table('domains_native_intents')->insert(['service_id'=>$open->service_id,'kind'=>'cancellation','created_at'=>now()]);
same(['cancelled','Cancelled','Order cancelled'],$orderWords(),'A retained cancellation intent counts as cancelled');
// A successful or in-flight payment prevents the unpaid-cancel message even before invoice status catches up.
// Each status runs alone, so either one on its own keeps the review.
foreach(['processing','succeeded'] as $status){
    $payment=App\Models\InvoiceTransaction::withoutEvents(fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$open->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>$status]));
    same([1,$status],[App\Models\InvoiceTransaction::where('invoice_id',$open->invoice_id)->count(),$payment->fresh()->status->value],'Fixture: only the '.$status.' payment is on the invoice');
    same(['cancelled','Under review','Order cancelled'],$orderWords(),'A '.$status.' payment on a cancelled service needs review');
    $paidCancel=$owned->present($open->domain->fresh());
    same(false,$paidCancel->order['unpaid']??false,'Payment evidence never says nothing was charged');
    App\Models\InvoiceTransaction::withoutEvents(fn()=>$payment->delete());
}
same(['cancelled','Cancelled','Order cancelled'],$orderWords(),'Without the payments the cancelled order reads as a plain cancel again');
echo "Unpaid acquisition retirement case passed.\n";$nativeSuccessful=true;
