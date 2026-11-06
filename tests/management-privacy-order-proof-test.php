<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic privacy native coverage.
declare(strict_types=1);
require __DIR__.'/Support/management-privacy.php';
use App\Models\{Invoice,InvoiceItem,InvoiceTransaction,Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Services\{ManagementEvidence,Refunds};
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-order-proof-placeholder.com'));$state->privacy=false;$op=$submit($d,$driver,'privacy_purchase');$pay($op);$state->fault='order_pending';same('uncertain',$management->run($op->id,$driver)->state,'Matching privacy expiry cannot confirm pending order');foreach(['order_id','order_domain','order_term'] as $fault){$state->fault=$fault;same('uncertain',$management->reconcile($op->id,$driver)->state,'Captured order must match identity/domain/one-year term');}$state->fault='';same('succeeded',$management->reconcile($op->id,$driver)->state,'Exact completed captured order plus expiry resolves');same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='PurchaseServices')),'Order reconciliation never replays purchase');
[$freeDomain,$freeDriver]=$holding(placeholderComFixtureName('manage-free-unknown-peer-placeholder.com'));$freeOp=$submit($freeDomain,$freeDriver,'lock',['locked'=>false]);$state->fault='SetRegLock';same('uncertain',$management->run($freeOp->id,$freeDriver)->state,'Unrelated free operation remains unresolved');
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-free-peer-placeholder.com'));$state->privacy=false;$op=$submit($d,$driver,'privacy_purchase');$pay($op);same('succeeded',$management->run($op->id,$driver)->state,'Unrelated free uncertainty never blocks reseller spending');
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-unknown-placeholder.com'));$state->cost='8.00';$state->privacy=false;$op=$submit($d,$driver,'privacy_purchase');$pay($op);$state->fault='PurchaseServices';same('uncertain',$management->run($op->id,$driver)->state,'Unknown paid write quarantined');commerceReject(fn()=>$refunds->refundHeld($staff,$op->id,'privacy_unavailable'));$count=count($state->calls);$management->run($op->id,$driver);same($count,count($state->calls),'Unknown paid privacy no retry');
$state->fault='';$state->privacy=true;$state->enabled=true;$state->expiry='2027-10-18';same('uncertain',$management->reconcile($op->id,$driver)->state,'Readback alone cannot invent missing original paid acknowledgement');

$nativeSuccessful=true;echo "management-privacy-order-proof-test.php passed\n";
