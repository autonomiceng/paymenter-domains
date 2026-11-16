<?php
// Native payment before any retirement sweep; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\{DB,Event};
use Paymenter\Extensions\Servers\Domains\Services\{NativePresenter,OwnedDomains,OwnerPorts,HolderPricing};
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
[$op,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('cancelled-renewal-payment-placeholder.com'),false);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$bill->update(['status'=>'cancelled']);
$presenter=new NativePresenter(new OwnedDomains($clock,new OwnerPorts),$native,new HolderPricing($prices));
$paidOutcome=null;
Event::listen(\App\Events\Invoice\Paid::class,static function($event)use($bill,$presenter,$user,&$paidOutcome):void {
    if($event->invoice->id===$bill->id){DB::afterCommit(static function()use($bill,$presenter,$user,&$paidOutcome):void {$paidOutcome=$presenter->invoiceOutcome($user,(int)$bill->id);});}
});
// A real native receipt triggers Paid and afterCommit; no renewal closure or daily sweep has run.
DB::transaction(fn()=>InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']));
same('renewal_cancelled_unpaid',$paidOutcome?->kind,'Paid mail must see the cancelled renewal before any sweep');
same('rejected',$op->fresh()->state,'Native late payment cannot revive cancelled renewal authority');
$before=$state->writes;[$engine,$writer]=$compose($b);$engine->run($op->id,$writer);
same($before,$state->writes,'Cancelled renewal payment sends nothing');
same('2027-10-18',$d->fresh()->provider_expires_at->format('Y-m-d'),'Cancelled renewal retains expiry');
same(1,InvoiceTransaction::where('invoice_id',$bill->id)->count(),'Keep the actual late receipt');
// Even a partial/processing receipt must close authority before native settlement erases Cancelled.
[$partial,$pd,$pb,$pi]=$makeRenewal(placeholderComFixtureName('cancelled-partial-payment-placeholder.com'),false);
$pi->update(['status'=>'cancelled']);
App\Helpers\ExtensionHelper::addProcessingPayment($pi,null,'1.00','0.00','cancelled-partial');
same('rejected',$partial->fresh()->state,'Processing late receipt closes the cancelled renewal');
App\Helpers\ExtensionHelper::addPayment($pi,null,'1.00','0.00','cancelled-partial');
App\Helpers\ExtensionHelper::addPayment($pi,null,\Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($partial->amount)-100),'0.00','cancelled-rest');
$closed=$partial->fresh()->getRawOriginal();
App\Helpers\ExtensionHelper::addPayment($pi,null,'1.00','0.00','cancelled-partial');
same($closed,$partial->fresh()->getRawOriginal(),'Repeated payment cannot replace closed authority');
same(2,$pi->transactions()->count(),'Gateway replay keeps the two actual late receipts');
same('renewal_cancelled_unpaid',$presenter->invoiceOutcome($user,(int)$pi->id)?->kind,'Partials retain the same closed mail outcome');
[$engine,$writer]=$compose($pb);$engine->run($partial->id,$writer);
same($before,$state->writes,'Partial settlement and replay never send a cancelled renewal');
// A still-pending invoice remains payable through the same native path.
[$normal,$nd,$nb,$ni]=$makeRenewal(placeholderComFixtureName('pending-normal-payment-placeholder.com'),false);
// Observe the receipt hook alone: other native payment listeners are outside its lock contract.
$ordinaryUserReads=[];$observeOrdinary=true;
DB::listen(static function($q)use(&$ordinaryUserReads,&$observeOrdinary):void{if($observeOrdinary&&str_contains($q->sql,'from "users"')){$ordinaryUserReads[]=$q->sql;}});
DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::beforePayment((int)$ni->id,$clock));
$observeOrdinary=false;same([],$ordinaryUserReads,'An ordinary renewal receipt takes no owner lock from the payment hook');
App\Helpers\ExtensionHelper::addPayment($ni,null,$normal->amount,'0.00','normal-renewal');
same(null,$presenter->invoiceOutcome($user,(int)$ni->id),'Ordinary payment retains ordinary paid mail');
[$engine,$writer]=$compose($nb);$engine->run($normal->id,$writer);
same($before+1,$state->writes,'Pending invoice payment still sends one renewal');
// An in-flight callback must record its real result without reviving cancelled authority.
require __DIR__.'/Support/reminder-mail.php';
$cash=static fn()=>\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)(\App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));
foreach(['succeeded','failed'] as $settles){
    [$processing,$xd,$xb,$xi]=$makeRenewal(placeholderComFixtureName('cancelled-processing-'.$settles.'-placeholder.com'),false);
    App\Helpers\ExtensionHelper::addProcessingPayment($xi,null,$processing->amount,'0.00','processing-before-cancel-'.$settles);
    $xi->refresh()->update(['status'=>'cancelled']);$callbackOutcome=null;
    Event::listen(\App\Events\Invoice\Paid::class,static function($event)use($xi,$presenter,$user,&$callbackOutcome):void {
        if($event->invoice->id===$xi->id){DB::afterCommit(static function()use($xi,$presenter,$user,&$callbackOutcome):void {$callbackOutcome=$presenter->invoiceOutcome($user,(int)$xi->id);});}
    });
    if($settles==='succeeded'){App\Helpers\ExtensionHelper::addPayment($xi,null,$processing->amount,'0.00','processing-before-cancel-'.$settles);}
    else{$xi->transactions()->sole()->update(['status'=>'failed']);}
    same($settles,$xi->transactions()->sole()->status->value,'Cancelled callback retains the real gateway result');
    same('rejected',$processing->fresh()->state,'In-flight settlement keeps cancelled renewal execution closed');
    if($settles==='succeeded'){same('renewal_funds_expired',$callbackOutcome?->kind,'Paid callback sees closed-renewal mail before any sweep');}
    $sent=$state->writes;[$engine,$writer]=$compose($xb);$engine->run($processing->id,$writer);
    same($sent,$state->writes,'Existing processing callback cannot send the cancelled renewal');
    $beforeCredit=$cash();$refunds=new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);
    $refunds->creditRejected($processing->id);$refunds->creditRejected($processing->id);
    same($beforeCredit+($settles==='succeeded'?\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($processing->amount):0),$cash(),'Only settled money returns once');
    if($settles==='succeeded'){
        \Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules::enroll($processing->server_id);
        $notices=new \Paymenter\Extensions\Servers\Domains\Services\Reminders($clock,$renewBindings);
        expect($notices->remind($xd->id),'Settled cancelled receipt sends the existing return notice');
        same(false,$notices->remind($xd->id),'Return notice sends once');
        same('domains_domain_order_refunded',\Illuminate\Support\Facades\Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last()->emailTemplate->key,'Closed renewal uses the return notice');
    }
}
// Deterministic stale-read/current-read seam: change real stored rows after the seed SELECT has returned.
// SQLite cannot prove MariaDB repeatable-read or deadlocks; this covers the unsafe state-filter decision and same-invoice retry.
\Paymenter\Extensions\Servers\Domains\Services\RenewalRetrySchema::install();
$staleFailures=[];
foreach(['held','retry'] as $transition){
    [$prior,$sd,$sb,$si]=$makeRenewal(placeholderComFixtureName('cancelled-stale-'.$transition.'-placeholder.com'),false);
    $key='stale-processing-'.$transition;App\Helpers\ExtensionHelper::addProcessingPayment($si,null,$prior->amount,'0.00',$key);
    $si->refresh()->update(['status'=>'cancelled']);DB::table('domains_operations')->where('id',$prior->id)->update(['state'=>'running']);
    $armed=true;$currentId=$prior->id;
    DB::listen(static function($q)use(&$armed,&$currentId,$prior,$si,$transition):void{
        if($armed&&str_starts_with($q->sql,'select * from "domains_operations"')&&str_contains($q->sql,'"invoice_id" = ?')&&in_array($si->id,$q->bindings)){
            $armed=false;DB::table('domains_operations')->where('id',$prior->id)->update(['state'=>$transition==='held'?'held':'rejected']);
            if($transition==='retry'){$row=$prior->getRawOriginal();unset($row['id']);$row['state']='queued';$row['operation_key'].=':replacement:1';$row['retry_number']=1;$currentId=(int)DB::table('domains_operations')->insertGetId($row);}
        }
    });
    App\Helpers\ExtensionHelper::addPayment($si,null,$prior->amount,'0.00',$key);
    $current=\Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($currentId);
    if($current->state!=='rejected'){$staleFailures[]=$transition.': cancelled authority remains '.$current->state;}
    same('succeeded',$si->transactions()->sole()->status->value,'Stale seed preserves the actual callback settlement');
}
same([],$staleFailures,'Retained renewal membership reaches current locks and closes current authority');

// Native addPayment already owns the invoice. An aborted lock attempt must roll back and accept a replay once.
[$victim,$vd,$vb,$vi]=$makeRenewal(placeholderComFixtureName('cancelled-lock-victim-placeholder.com'),false);$vi->update(['status'=>'cancelled']);
$abortOnce=true;DB::listen(static function($q)use(&$abortOnce):void {if($abortOnce&&str_contains($q->sql,'from "users"')){$abortOnce=false;throw new \Illuminate\Database\DeadlockException('Deadlock found when trying to get lock; try restarting transaction');}});
try{App\Helpers\ExtensionHelper::addPayment($vi,null,$victim->amount,'0.00','lock-victim');throw new RuntimeException('Expected payment abort');}catch(\Illuminate\Database\DeadlockException){}
same(0,$vi->transactions()->count(),'Lock victim rolls back its receipt');same('cancelled',$vi->fresh()->status,'Aborted payment does not mark invoice Paid');
App\Helpers\ExtensionHelper::addPayment($vi,null,$victim->amount,'0.00','lock-victim');
same('succeeded',$vi->transactions()->sole()->status->value,'Same gateway event settles after retry');same('rejected',$victim->fresh()->state,'Replayed payment still closes cancelled renewal');

// Rejected receipt rerouting must not close the target invoice as a side effect.
[$target,$td,$tb,$ti]=$makeRenewal(placeholderComFixtureName('cancelled-payment-rerouting-placeholder.com'),false);
$ti->update(['status'=>'cancelled']);$routed=$ni->transactions()->sole();$routed->invoice_id=$ti->id;
commerceReject(fn()=>$routed->save());
same('awaiting_payment',$target->fresh()->state,'Immutable routing refuses before the payment closure hook');
same(0,$ti->transactions()->count(),'Receipt rerouting adds no payment to the target invoice');
echo "Cancelled renewal payment closes authority and exposes closed mail before any sweep.\n";$nativeSuccessful=true;
