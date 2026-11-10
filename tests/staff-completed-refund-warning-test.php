<?php
// A completed refund must not tell staff to retry it after an old Cancel and refund click.
// Native refunds and staff actions verify the saved return results and the refused action's notification together.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions;
use Paymenter\Extensions\Servers\Domains\Services\{ReceiptReturns,StaffOperationReview};

// Account credit has finished even when an old action reaches the exception handler again.
$op=$held('staff-completed-credit-placeholder.com');
$stamp=StaffOperationReview::stamp($op,$op->domain);
$action=collect(OperationActions::make($op,$stamp))->first(fn($a)=>$a->getName()==='refund');
$run($op,'refund');
same('account_credit',$op->fresh()->funding['refund']['to'],'Account credit was recorded');
same(null,OperationActions::refundPending($op->fresh(),'guard_refused'),'A completed credit needs no retry warning');
session()->forget('filament.notifications');
($action->getActionFunction())(['review_key'=>$ticket($op->fresh(),'refund'),'reason'=>'Old refund confirmation']);
$messages=collect(session('filament.notifications',[]));
expect($messages->isNotEmpty(),'The refused old click gives staff a notification');
expect(!$messages->contains(fn($n)=>($n['title']??null)==='Cancelled, refund not finished'),'An old click never claims a completed refund is unfinished');

// A saved gateway plan can still be pending. Hide its warning only after the same return completes.
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic receipt','type'=>'gateway','enabled'=>true]);
$op=$held('staff-completed-gateway-placeholder.com');
DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'receipt_completed_warning']);
MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='pending';
$run($op,'refund');
expect(ReceiptReturns::outstanding($op->fresh())>0,'The gateway return is unfinished');
same('Cancelled, refund not finished',OperationActions::refundPending($op->fresh(),'guard_refused')?->getTitle(),'A pending gateway return keeps its warning');
$key=array_key_last(MoneyReceiptGateway::$returns);$sends=MoneyReceiptGateway::$sends;
MoneyReceiptGateway::complete(App\Models\Invoice::findOrFail($op->invoice_id),$key);
$run($op->fresh(),'credit');
same(0,ReceiptReturns::outstanding($op->fresh()),'The gateway return finished');
same(null,OperationActions::refundPending($op->fresh(),'guard_refused'),'A completed gateway return needs no retry warning');
same($sends,MoneyReceiptGateway::$sends,'Completion reuses the same return');
echo "Completed refunds do not show an unfinished refund warning.\n";
$nativeSuccessful=true;
