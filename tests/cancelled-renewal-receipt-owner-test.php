<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';

use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\DB;

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
    $cash=static fn($owner)=>(string)(App\Models\Credit::where('user_id',$owner)->where('currency_code','USD')->value('amount')??'0.00');$credits=[$cash($user->id),$cash($foreign->id)];
    // The payment is always recorded; a damaged owner keeps the invoice cancelled and the money with staff.
    ExtensionHelper::addPayment($invoice,null,$op->amount,'0.00','receipt-owner-'.$renewalState);
    ExtensionHelper::addPayment($invoice->fresh(),null,$op->amount,'0.00','receipt-owner-'.$renewalState);
    same(['succeeded','cancelled'],[$invoice->transactions()->sole()->status->value,$invoice->fresh()->status],'The receipt is recorded once and the invoice stays cancelled: '.$renewalState);
    same($credits,[$cash($user->id),$cash($foreign->id)],'A mismatched owner gets no automatic credit: '.$renewalState);
    same($before,$op->fresh()->getRawOriginal(),'Payment never changes renewal authority: '.$renewalState);
    same($holding,$domain->fresh()->getRawOriginal(),'Payment never changes the holding: '.$renewalState);
    same($writes,$state->writes,'Owner mismatch never sends a renewal: '.$renewalState);
    same(0,DB::transactionLevel(),'Native payment completes or rolls back its transaction: '.$renewalState);
    echo 'Mismatched-owner '.$renewalState.' receipt behavior passed.'.PHP_EOL;
}
$nativeSuccessful=true;
