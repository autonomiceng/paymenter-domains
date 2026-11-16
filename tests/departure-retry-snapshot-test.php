<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic repeatable-read snapshot only.
declare(strict_types=1);
require __DIR__.'/departure-retry-receipt-test.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureProof,Refunds};

$nativeSuccessful=false;
// SQLite has no repeatable-read views. Hide the retry child from plain reads as if the
// payment snapshot began before its creation and departure; locking reads stay current.
$snapshot=true;$locked=false;
Operation::addGlobalScope('receipt_retry_snapshot',static function($query)use(&$snapshot,&$locked,$child):void{
    if(!$snapshot){return;}
    if($query->getQuery()->lock){$locked=true;return;}
    $query->where('domains_operations.id','<>',$child->id);
});
same(null,Operation::where('operation_key',$childKey)->first(),'The old payment snapshot cannot see the retry child');
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']);
$before=$cash($owner);$error=null;
try{App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,'1.20','0.00','departed-retry-snapshot');}
catch(Throwable $e){$error=$e;}
finally{$snapshot=false;}
same(true,$locked,'Receipt admission reads the current locked operations');
same(null,$error?->getMessage(),'A late receipt accepts a retry child created after its payment snapshot');
same(1,App\Models\InvoiceTransaction::where('invoice_id',$invoice->id)->where('transaction_id','departed-retry-snapshot')->count(),'The late receipt is retained on the old invoice');
$refunds=new Refunds($clock);$refunds->creditRejected((int)$child->id);$refunds->creditRejected((int)$child->id);
same($before+120,$cash($owner),'The admitted receipt credits the old owner exactly once');
same(['departed',null,true],[$d->fresh()->status,$d->fresh()->reservation_name,DepartureProof::verified($d->fresh())],'Receipt admission preserves the departed holding');
echo "A late receipt uses locked retry history despite an older payment snapshot.\n";
$nativeSuccessful=true;
