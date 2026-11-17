<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';

use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\MoneyScopeChanged;

app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$foreign=commerceUser();
foreach(['awaiting_payment','queued','held','rejected'] as $renewalState){
    [$op,$domain,,$invoice]=$makeRenewal(placeholderComFixtureName('receipt-owner-'.str_replace('_','-',$renewalState).'-placeholder.com'),false);
    $invoice->update(['status'=>'cancelled']);
    if($renewalState==='rejected'){$cycles->retireUnpaid((int)$op->id);}
    else{DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$renewalState]);}
    // Model events forbid this routing change; simulate retained damage without changing native guards.
    DB::table('invoices')->where('id',$invoice->id)->update(['user_id'=>$foreign->id]);
    $before=$op->fresh()->getRawOriginal();$holding=$domain->fresh()->getRawOriginal();$writes=$state->writes;
    $refused=false;
    try{ExtensionHelper::addPayment($invoice,null,$op->amount,'0.00','receipt-owner-'.$renewalState);}
    catch(MoneyScopeChanged){$refused=true;}
    same($renewalState!=='rejected',$refused,'Only an open renewal refuses a mismatched invoice owner: '.$renewalState);
    same($renewalState==='rejected'?1:0,$invoice->transactions()->count(),'Receipt commits only after renewal authority is closed: '.$renewalState);
    same($renewalState==='rejected'?'paid':'cancelled',$invoice->fresh()->status,'Refusal rolls back native invoice settlement: '.$renewalState);
    if($renewalState==='rejected'){
        same('succeeded',$invoice->transactions()->sole()->status->value,'Closed renewal retains the actual payment');
        ExtensionHelper::addPayment($invoice,null,$op->amount,'0.00','receipt-owner-'.$renewalState);
        same(1,$invoice->transactions()->count(),'Gateway replay records a closed renewal payment once');
    }
    same($before,$op->fresh()->getRawOriginal(),'Payment never changes renewal authority: '.$renewalState);
    same($holding,$domain->fresh()->getRawOriginal(),'Payment never changes the holding: '.$renewalState);
    same($writes,$state->writes,'Owner mismatch never sends a renewal: '.$renewalState);
    same(0,DB::transactionLevel(),'Native payment completes or rolls back its transaction: '.$renewalState);
    echo 'Mismatched-owner '.$renewalState.' receipt behavior passed.'.PHP_EOL;
}
$nativeSuccessful=true;
