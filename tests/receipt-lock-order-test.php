<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. SQLite records requested locks, not database contention.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Invoice,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{MoneyDecisions,MoneyOutcome,MoneyRequest,RenewalCycles};

[$op,$d,,$bill]=$makeRenewal(placeholderComFixtureName('receipt-lock-order-placeholder.com'),false);
$bill->update(['status'=>'cancelled']);
$locks=[];$record=false;
foreach([User::class,Invoice::class] as $model){
    $model::addGlobalScope('receipt_lock_order',static function($query)use(&$locks,&$record):void{
        if($record&&$query->getQuery()->lock){$locks[]=$query->getModel()->getTable();}
    });
}
$record=true;
$result=MoneyDecisions::run(new MoneyRequest($d),static fn($l)=>true);
$record=false;
expect($result->outcome===MoneyOutcome::Applied,'The money decision takes the holding locks successfully');
expect(array_slice($locks,0,2)===['users','invoices'],'Money decisions lock the owner before the invoice');
$locks=[];$record=true;
DB::transaction(static fn()=>RenewalCycles::beforePayment((int)$bill->id,$clock));
$record=false;
expect(array_slice($locks,0,2)===['users','invoices'],'Receipt admission locks the owner before the invoice');
expect($op->fresh()->state==='rejected','Receipt admission still closes cancelled unpaid renewal authority');
expect($bill->transactions()->count()===0,'Receipt admission itself records no payment');
$nativeSuccessful=true;echo "Receipt admission and money decisions use owner then invoice locks.\n";
