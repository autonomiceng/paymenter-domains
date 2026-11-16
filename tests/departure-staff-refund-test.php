<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic card returns only.
declare(strict_types=1);
require __DIR__.'/departure-late-receipt-test.php';
require __DIR__.'/Support/money-receipt.php';
use App\Models\Gateway;
use Paymenter\Extensions\Servers\Domains\Services\{InvoiceMoney,ReceiptReturns,StaffOperationReview,StaffOperations,StaffReviewRefused};

$nativeSuccessful=false;
Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic card receipt','type'=>'gateway','enabled'=>true]);
MoneyReceiptGateway::$lost=false;
$before=$cash($owner);
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),'MoneyReceipt','1.20','0.00','departed-staff-receipt');
MoneyReceiptGateway::automatic($invoice->fresh(),'departed-staff-receipt',120,'pending');
$refunds->creditRejected((int)$renewal->id);
same(120,ReceiptReturns::outstanding($renewal->fresh()),'The departed renewal retains its in-flight card return');
same($before,$cash($owner),'An in-flight card return adds no duplicate account credit');
MoneyReceiptGateway::complete($invoice->fresh(),'departed-staff-receipt:excess');
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.operations.credit','admin.invoices.update']);
$ticket=static fn($actor)=>StaffOperationReview::ticket((int)$actor->id,(int)$d->id,(int)$renewal->id,'credit',StaffOperationReview::stamp($renewal->fresh(),$d->fresh()),$clock);
$run=static fn($actor,$ticket)=>(new StaffOperations($clock))->run($actor,(int)$d->id,(int)$renewal->id,'credit',$ticket,'Checked the original payment return.');
$denied=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.operations.credit']);
$refused=false;try{$run($denied,$ticket($denied));}catch(StaffReviewRefused){$refused=true;}
same(true,$refused,'Departed refund retry still requires invoice permission');
$refused=false;try{$run($staff,$ticket($denied));}catch(StaffReviewRefused){$refused=true;}
same(true,$refused,'Departed refund retry still binds its ticket to the actor');
$review=$ticket($staff);$run($staff,$review);
same(0,ReceiptReturns::outstanding($renewal->fresh()),'Staff retry recovers the original departed card return');
$history=$renewal->fresh()->policy['staff_actions']??[];
same([1,'credit','completed',(int)$staff->id],[count($history),$history[0]['action']??null,$history[0]['phase']??null,$history[0]['actor_id']??null],'The released full decision records the staff refund retry');
same([1,$before],[MoneyReceiptGateway::$sends,$cash($owner)],'Staff recovery neither sends another return nor adds duplicate credit');
same(true,InvoiceMoney::settled(InvoiceMoney::project((int)$invoice->id)),'The departed invoice is settled after recovery');
$refused=false;try{$run($staff,$review);}catch(StaffReviewRefused){$refused=true;}
same(true,$refused,'The consumed staff review cannot be replayed');
echo "Staff refund retry consumes a released full review and recovers the original card return.\n";
$nativeSuccessful=true;
