<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic card returns only.
declare(strict_types=1);
require __DIR__.'/departure-late-receipt-test.php';
require __DIR__.'/Support/money-receipt.php';
use App\Models\Gateway;
use Paymenter\Extensions\Servers\Domains\Services\{ReceiptReturns,RenewalCycles};

$nativeSuccessful=false;
// A card payment on the cancelled renewal invoice after the departure starts an automatic return that is still pending.
Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic card receipt','type'=>'gateway','enabled'=>true]);
MoneyReceiptGateway::$lost=false;
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),'MoneyReceipt','1.20','0.00','departed-history-receipt');
MoneyReceiptGateway::automatic($invoice->fresh(),'departed-history-receipt',120,'pending');
$refunds->creditRejected((int)$renewal->id);
same(120,ReceiptReturns::outstanding($renewal->fresh()),'The departed renewal has an in-flight card return');
$note=RenewalCycles::lateNote($renewal->fresh());
same(true,$note!==null,'The in-flight return has a customer status');
// Customer History still tells the owner where that money is, though the departure closed the renewal.
$progress=$owners->present($d->fresh())->progress;
same(1,count(array_filter($progress,fn($row)=>($row['note']??null)===$note)),'Customer History keeps the late return status');
echo "A return paid after departure keeps its status in customer History.\n";
$nativeSuccessful=true;
