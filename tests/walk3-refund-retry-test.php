<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions;
use Paymenter\Extensions\Servers\Domains\Money\Returns\ReceiptReturns;
$op=$held('walk3-refund-placeholder.com');
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Receipt fixture','type'=>'gateway','enabled'=>true]);
App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-receipt']);
MoneyReceiptGateway::$lost=false;
$run($op,'refund');
same(0,ReceiptReturns::outstanding($op->fresh()),'The original payment return settled');
$before=[MoneyReceiptGateway::$sends,App\Models\InvoiceTransaction::count(),App\Models\Credit::sum('amount')];
commerceReject(fn()=>$run($op,'credit'));
same($before,[MoneyReceiptGateway::$sends,App\Models\InvoiceTransaction::count(),App\Models\Credit::sum('amount')],'A settled return cannot move money again');
Illuminate\Support\Facades\Auth::setUser($staff);
same(false,OperationActions::relevant('credit',$op->fresh()),'A settled return offers no retry');
$nativeSuccessful=true; echo "Settled refund retry passed.\n";
