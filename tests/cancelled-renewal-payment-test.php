<?php
// Native payment on a cancelled renewal is recorded, then returned as account credit; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureReadyRegistration = true;
$reuseRenewalHolding = true;
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Credit,Gateway,InvoiceTransaction};
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\{Crypt,DB,Event};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,OwnedDomains,OwnerPorts,PricingRule,Refunds,RenewalFunding};
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$cash=static fn()=>PricingRule::minor((string)(Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));
$paid=[];Event::listen(\App\Events\Invoice\Paid::class,static function($event)use(&$paid):void {$paid[]=(int)$event->invoice->id;});
$send=static function($op,$binding)use($compose,$state):int {$before=$state->writes;[$engine,$writer]=$compose($binding);$engine->run($op->id,$writer);return $state->writes-$before;};
// Inside core's payment transaction Domains reads no customer row, so it cannot lock one.
$userReads=0;DB::listen(static function($q)use(&$userReads):void {
    if(!str_contains($q->sql,'from "users"')){return;}
    $calls=array_map(static fn($f)=>($f['class']??'').'::'.$f['function'],debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
    // Core's addPayment transaction, before its after-commit callbacks run.
    if(in_array('App\\Helpers\\ExtensionHelper::addPayment',$calls,true)&&!in_array('Illuminate\\Database\\DatabaseTransactionsManager::commit',$calls,true)&&DB::transactionLevel()>0){$userReads++;}
});

// A full receipt on a cancelled renewal: recorded, the invoice stays cancelled, credited once after commit.
[$op,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('cancelled-renewal-payment-placeholder.com'),false);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$bill->update(['status'=>'cancelled']);
$before=$cash();$reads=$userReads;
ExtensionHelper::addPayment($bill,null,$op->amount,'0.00','cancelled-full');
same($reads,$userReads,'The payment transaction takes no customer lock for a cancelled renewal');
same('succeeded',$bill->transactions()->sole()->status->value,'The payment is recorded');
same('cancelled',$bill->fresh()->status,'The cancelled renewal invoice stays cancelled');
same([],$paid,'Paid never fires for a cancelled renewal invoice');
same('rejected',$op->fresh()->state,'The renewal closes unsent after commit');
same($before+PricingRule::minor($op->amount),$cash(),'The payment returns once as account credit');
ExtensionHelper::addPayment($bill->fresh(),null,$op->amount,'0.00','cancelled-full');
(new Refunds($clock))->creditRenewalReceipts((int)$bill->id);
same(1,$bill->transactions()->count(),'A gateway replay keeps one receipt');
same($before+PricingRule::minor($op->amount),$cash(),'A replay and a second pass credit nothing more');
same(0,$send($op,$b),'A cancelled renewal sends nothing');
same('2027-10-18',$d->fresh()->provider_expires_at->format('Y-m-d'),'A cancelled renewal keeps its expiry');

// Processing then settled parts: the processing receipt closes the renewal, so later parts take the native late path (the
// invoice turns paid) and the refund sweep returns each settled part once; none revives the renewal.
[$partial,$pd,$pb,$pi]=$makeRenewal(placeholderComFixtureName('cancelled-partial-payment-placeholder.com'),false);
$pi->update(['status'=>'cancelled']);$before=$cash();
ExtensionHelper::addProcessingPayment($pi,null,'1.00','0.00','cancelled-partial');
same('rejected',$partial->fresh()->state,'A processing receipt closes the cancelled renewal');
same($before,$cash(),'Processing money is not credited yet');
ExtensionHelper::addPayment($pi,null,'1.00','0.00','cancelled-partial');
ExtensionHelper::addPayment($pi,null,PricingRule::decimal(PricingRule::minor($partial->amount)-100),'0.00','cancelled-rest');
ExtensionHelper::addPayment($pi,null,'1.00','0.00','cancelled-partial');
same(2,$pi->transactions()->count(),'A gateway replay keeps the two actual receipts');
$returns=new Refunds($clock);$returns->retryPending();$returns->retryPending();
same(['paid','rejected',$before+PricingRule::minor($partial->amount)],[$pi->fresh()->status,$partial->fresh()->state,$cash()],'Settled parts on the closed renewal return once');
same(0,$send($partial,$pb),'Partial settlement never sends a cancelled renewal');

// A still-pending invoice remains payable through the same native path.
[$normal,$nd,$nb,$ni]=$makeRenewal(placeholderComFixtureName('pending-normal-payment-placeholder.com'),false);
$paid=[];$reads=$userReads;ExtensionHelper::addPayment($ni,null,$normal->amount,'0.00','normal-renewal');
same($reads,$userReads,'An ordinary renewal receipt takes no customer lock in the payment transaction');
same(['paid',[(int)$ni->id]],[$ni->fresh()->status,$paid],'An ordinary renewal invoice still becomes Paid');
same(1,$send($normal,$nb),'A pending invoice payment still sends one renewal');

// A processing callback that settles after cancellation keeps the real gateway result and returns only settled money.
require __DIR__.'/Support/reminder-mail.php';
foreach(['succeeded','failed'] as $settles){
    [$processing,$xd,$xb,$xi]=$makeRenewal(placeholderComFixtureName('cancelled-processing-'.$settles.'-placeholder.com'),false);
    ExtensionHelper::addProcessingPayment($xi,null,$processing->amount,'0.00','processing-before-cancel-'.$settles);
    $xi->refresh()->update(['status'=>'cancelled']);$beforeCredit=$cash();
    if($settles==='succeeded'){ExtensionHelper::addPayment($xi,null,$processing->amount,'0.00','processing-before-cancel-'.$settles);}
    else{$xi->transactions()->sole()->update(['status'=>'failed']);}
    // A failed callback moved no money: the renewal stays unpaid on its cancelled invoice for the normal unpaid retirement.
    same([$settles,'cancelled',$settles==='succeeded'?'rejected':'awaiting_payment'],[$xi->transactions()->sole()->status->value,$xi->fresh()->status,$processing->fresh()->state],'The callback keeps its real result and the renewal stays closed');
    same(0,$send($processing,$xb),'A processing callback cannot send the cancelled renewal');
    (new Refunds($clock))->creditRejected($processing->id);
    same($beforeCredit+($settles==='succeeded'?PricingRule::minor($processing->amount):0),$cash(),'Only settled money returns, once');
    if($settles==='succeeded'){
        \Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules::enroll($processing->server_id);
        $notices=new \Paymenter\Extensions\Servers\Domains\Services\Reminders($clock,$renewBindings);
        expect($notices->remind($xd->id),'A settled cancelled receipt sends the return notice');
        same(false,$notices->remind($xd->id),'The return notice sends once');
        same('domains_domain_order_refunded',\Illuminate\Support\Facades\Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last()->emailTemplate->key,'A closed renewal uses the return notice');
    }
}

// A missed or failed after-commit handler: the payment is recorded, the invoice stays cancelled, nothing is sent, and
// the scheduled sweep returns the money exactly once.
foreach(['failed','missed'] as $case){
    [$late,$ld,$lb,$li]=$makeRenewal(placeholderComFixtureName('cancelled-handler-'.$case.'-placeholder.com'),false);
    $li->update(['status'=>'cancelled']);$before=$cash();
    if($case==='failed'){$abort=true;DB::listen(static function($q)use(&$abort):void {if($abort&&str_contains($q->sql,'from "users"')&&in_array('creditRenewalReceipts',array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),'function'),true)){$abort=false;throw new \Illuminate\Database\DeadlockException('Deadlock found when trying to get lock; try restarting transaction');}});}
    else{app()->bind(Refunds::class,static fn()=>throw new RuntimeException('Synthetic lost worker'));}
    ExtensionHelper::addPayment($li,null,$late->amount,'0.00','handler-'.$case);
    app()->offsetUnset(Refunds::class);$abort=false;
    same(['succeeded','cancelled','awaiting_payment',$before],[$li->transactions()->sole()->status->value,$li->fresh()->status,$late->fresh()->state,$cash()],'The receipt is recorded and the invoice stays cancelled: '.$case);
    same(0,$send($late,$lb),'A missed handler sends nothing: '.$case);
    $refunds=new Refunds($clock);$account=Account::where('server_id',$late->server_id)->sole();
    $refunds->creditRetired($account);$refunds->creditRetired($account);
    same(['rejected',$before+PricingRule::minor($late->amount)],[$late->fresh()->state,$cash()],'The sweep credits the payment exactly once: '.$case);
    same(0,$send($late,$lb),'The swept renewal sends nothing: '.$case);
}

// Early replacement uses the first locked observation of cancellation and the current settlement.
require __DIR__.'/Support/money-receipt.php';
Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic receipt ledger','type'=>'gateway','enabled'=>true]);
$product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
require_once dirname(__DIR__).'/Domains/routes/owned.php';
require_once dirname(__DIR__).'/Domains/routes/web.php';
app('router')->getRoutes()->refreshNameLookups();
$owned = new OwnedDomains($clock, new OwnerPorts, billing: $billing);
$replacementFailures = [];
foreach (['after-cancel', 'before-cancel', 'late-observation', 'full-after-closure', 'processing-before-cancel'] as $case) {
    $earlyTime = new DateTimeImmutable('2027-09-26T12:00:00Z');
    [$early, $holding, $binding, $original] = $makeRenewal(
        placeholderComFixtureName('cancelled-early-'.$case.'-placeholder.com'),
        false, 1, false, static function () use ($clock, $earlyTime): void { $clock->instant = $earlyTime; }
    );
    $clock->instant = $earlyTime;
    $before = $cash();
    $reference = 'early-cancel-'.$case;
    $amount = $case === 'full-after-closure' ? $early->amount : '1.00';
    if ($case === 'before-cancel') {
        $receipt = ExtensionHelper::addPayment($original, 'MoneyReceipt', '1.00', '0.00', $reference);
        same('pending', $original->fresh()->status, 'The partial payment precedes cancellation');
    }
    if ($case === 'processing-before-cancel') {
        $receipt = ExtensionHelper::addProcessingPayment($original, null, $amount, '0.00', $reference);
    }
    $original->refresh()->update(['status' => 'cancelled']);
    if ($case === 'after-cancel') {
        $receipt = ExtensionHelper::addPayment($original, null, '1.00', '0.00', $reference);
    } elseif ($case === 'late-observation') {
        $receipt = InvoiceTransaction::withoutEvents(
            static fn () => ExtensionHelper::addPayment($original, null, '1.00', '0.00', $reference)
        );
        $clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z');
    } elseif ($case === 'full-after-closure') {
        $receipt = ExtensionHelper::addProcessingPayment($original, null, $amount, '0.00', $reference);
    }
    $sweep = new Refunds($clock);
    $account = Account::where('server_id', $early->server_id)->sole();
    if ($case === 'before-cancel') {
        MoneyReceiptGateway::automatic($original, $reference, 100, 'pending');
        $sweep->creditRetired($account);
        commerceReject(fn () => $billing->requestOptions($holding));
        commerceReject(fn () => $billing->invoice($holding->id, $user));
        same(1, $holding->operations()->where('action', 'renew')->count(), 'A reservation cannot issue a replacement');
        $clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z');
        MoneyReceiptGateway::complete($original, $reference.':excess');
    }
    if (in_array($case, ['full-after-closure', 'processing-before-cancel'], true)) {
        $sweep->creditRetired($account);
        commerceReject(fn () => $billing->invoice($holding->id, $user));
        same($before, $cash(), 'Processing payment neither funds a replacement nor creates credit');
        $clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z');
        ExtensionHelper::addPayment($original, null, $amount, '0.00', $reference);
    }
    $sweep->creditRetired($account);
    $sweep->creditRetired($account);
    $closed = $early->fresh(); $closedRecord = renewalRecord($closed);
    $terminal = [
        $original->fresh()->status, $closed->state, \Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt::of($closed)->terminal()['evidence']['transactions'],
    ];
    if ($terminal !== ['cancelled', 'rejected', [(int) $receipt->id]]) {
        $replacementFailures[$case] = 'Closure lost its cancellation or payment identity: '.json_encode($terminal);
        continue;
    }
    same($before + ($case === 'before-cancel' ? 0 : PricingRule::minor($amount)), $cash(), 'Settlement returns the receipt once: '.$case);
    if ($case === 'late-observation') {
        commerceReject(fn () => $billing->requestOptions($holding));
        commerceReject(fn () => $billing->invoice($holding->id, $user));
        commerceReject(fn () => $billing->invoice($holding->id));
        continue;
    }
    $tampered = [...$closed->funding, 'auto_pay' => [...$closed->funding['auto_pay'], 'status' => 'uncertain']];
    $unknown = $closed->funding;
    if ($case === 'before-cancel') {
        $key = array_key_first($unknown['receipt_return_results']);
        $unknown['receipt_return_results'][$key]['state'] = 'unknown';
    } else {
        $unknown['late_credits'][0]['credit_id'] = 99999999;
    }
    foreach ([$tampered, $unknown] as $unproved) {
        DB::table($closed->getTable())->where('id', $closed->id)->update(['funding' => Crypt::encryptString(json_encode($unproved, JSON_THROW_ON_ERROR))]);
        commerceReject(fn () => $billing->requestOptions($holding));
        commerceReject(fn () => $billing->invoice($holding->id, $user));
    }
    DB::table($closed->getTable())->where('id', $closed->id)->update(['funding' => $closed->getRawOriginal('funding')]);
    try {
        expect(in_array(1, $billing->requestOptions($holding)['terms'], true), 'Settled early closure offers renewal');
        expect(isset($owned->present($holding->fresh())->renewNow['url']), 'The owner can request the fresh invoice');
        $replacement = $billing->invoice($holding->id, $case === 'after-cancel' ? $user : null);
        same($replacement->id, $billing->invoice($holding->id, $user)->id, 'Replay keeps one replacement invoice');
        same(2, $holding->operations()->where('action', 'renew')->count(), 'Exactly one new renewal request');
        same($closedRecord, renewalRecord($early), 'Issuance preserves the closed request');
        ExtensionHelper::addPayment($replacement, null, $replacement->total);
        DB::transaction(function () use ($replacement, $holding): void {
            $locks = HoldingLocks::take($holding);
            RenewalFunding::requireCurrent($locks['operations']->firstWhere('invoice_id', $replacement->id), $locks);
        });
    } catch (DomainException $e) {
        $replacementFailures[$case] = $e->getMessage();
    }
}
same([], $replacementFailures, 'Settled early replacements: '.json_encode($replacementFailures));

// Rejected receipt rerouting must not close the target invoice as a side effect.
[$target,$td,$tb,$ti]=$makeRenewal(placeholderComFixtureName('cancelled-payment-rerouting-placeholder.com'),false);
$ti->update(['status'=>'cancelled']);$routed=$ni->transactions()->sole();$routed->invoice_id=$ti->id;
commerceReject(fn()=>$routed->save());
same('awaiting_payment',$target->fresh()->state,'Immutable routing refuses the move');
same(0,$ti->transactions()->count(),'Receipt rerouting adds no payment to the target invoice');
echo "A cancelled renewal payment is recorded and returned once as account credit.\n";$nativeSuccessful=true;
