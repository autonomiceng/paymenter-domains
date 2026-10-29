<?php
// Synthetic vendor facts and retained slice28 financial gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/transfer-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, HistoryAcceptances, OperationReconciliation, RegistrarRegistry};
require __DIR__.'/transfer-namesilo-observation.php';
$observe->beforeRead=static fn()=>same(0,DB::transactionLevel(),'Read outside locks');
$registry=new RegistrarRegistry; $registry->register('namesilo',['token'],$verifier,fn($b,$c)=>new Driver(new Client($b,$c['token'],$wire),['renew'],$clock));
$readBindings=new AccountBindings($registry); $reconcile=new OperationReconciliation($claims,$readBindings,$clock);
$prepare=static function($label)use($newTransfer,$makeTransfer,&$engine,&$binding,$clock,$now,$state,$observe){
    $clock->instant=$now; $state->rdapUnknown=false; $state->mode=''; $op=$newTransfer(placeholderComFixtureName('ns-transfer-'.$label.'-placeholder.com'),1);
    $payload=$op->payload; unset($payload['privacy']); $op->update(['payload'=>$payload]); // Actual checkout defaults privacy off at dispatch.
    $makeTransfer(1); $op=$engine->execute($op->id); same(false,$op->attempt['transfer_admission']['privacy'],'Dispatch default frozen at seal');
    HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'namesilo_transfer_v1'));
    $observe->name=$op->domain->domain; $observe->fault=''; $observe->status='Transfer Completed'; $observe->expiration=['expiration'=>'2028-01-01']; $observe->calls=[];
    $clock->instant=$now->modify('+6 minutes'); return $op;
};
$unresolved=static function(array $faults)use($prepare,$observe,$reconcile): void {
    foreach ($faults as $fault) {
        $op=$prepare($fault); $observe->fault=$fault;
        if (str_starts_with($fault,'needs')) { $observe->status='Domain is Locked'; $observe->fault=$fault==='needsowned'?'rejectowned':'rejectcharge'; }
        if (str_starts_with($fault,'reject')) { $observe->status='Transfer Rejected'; }
        if ($fault==='reversed') { App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
        if ($fault==='race') { $observe->during=fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
        $r=$reconcile->run($op->id); same('uncertain',$r->state,'Missing or contradictory proof stays unresolved'); same(null,$r->cost['actual']??null,'No inferred charge');
        same(null,$r->domain->fresh()->observation['nameservers']??null,'Unproven NameSilo transfers do not save nameserver facts');
        same(null,$r->domain->provider_expires_at,'No inferred expiry'); same(null,$r->domain->renewal_policy['billing_anchor']??null,'No premature anchor');
        expect($r->policy['reconciliation']['staff_alert'],'Unresolved staff alert retained'); same(null,$r->attempt['transfer_terminal']??null,'No terminal proof');
        if (str_starts_with($fault,'reject')) { same('transfer_rejection_contradiction',$r->policy['reconciliation']['reason'],'Owned/charged rejection contradiction'); }
        if (str_starts_with($fault,'needs')) { same('transfer_status_contradiction',$r->policy['reconciliation']['reason'],'Owned/charged needs-action contradiction'); }
    }
};
