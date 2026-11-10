<?php
// Enom d1392a8 late retired and unsuitable paid renewal refunds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
\Paymenter\Extensions\Servers\Domains\Services\RenewalRetrySchema::install();
$refunds=new Refunds($clock); $staff=commerceUser();
// Native SQLite is needed to exercise Paid/afterCommit against the retained invoice and operation bindings.
$presenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter(
    new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts),$native,
    new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
$role=Role::create(['name'=>'Renewal refunds','permissions'=>['admin.domains.manage','admin.invoices.update','admin.invoice_transactions.create']]); $staff->update(['role_id'=>$role->id]);
$credit=static fn()=>PricingRule::minor((string)(Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));
// Corrections do not constitute an attempt: restored signed funding may execute once with its audit retained.
[$corrected,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-corrected-funding-placeholder.com')); $corrections=new FinancialAdjustments($clock);
$corrections->append($staff,$corrected->id,'renew_reverse','-1.00','payment_correction');
commerceReject(fn()=>DB::transaction(fn()=>RenewalFunding::requireCurrent($corrected->fresh(),HoldingLocks::take($d))));
$corrections->append($staff,$corrected->id,'renew_restore','1.00','payment_correction');
$history=$corrected->fresh()->funding['financial_adjustments']; $raw=$corrected->fresh()->getRawOriginal();
foreach(['null_history','unknown_fact','foreign_transaction'] as $case){
    $bad=['financial_adjustments'=>$history];
    if($case==='null_history'){$bad['financial_adjustments']=null;}
    elseif($case==='unknown_fact'){$bad['claim']=null;}
    else{$bad['financial_adjustments'][0]['transaction_id']=999999;}
    Operation::withoutEvents(fn()=>$corrected->fresh()->update(['funding'=>$bad]));
    if($case==='foreign_transaction'){commerceReject(fn()=>DB::transaction(fn()=>RenewalFunding::requireCurrent($corrected->fresh(),HoldingLocks::take($d))));}
    else{same(false,RenewalCycles::virgin($corrected->fresh()),'Unknown funding history cannot admit');}
    DB::table('domains_operations')->where('id',$corrected->id)->update($raw);
}
[$engine,$writer,$completion]=$compose($b); $writes=$state->writes;
same('pending',$engine->run($corrected->id,$writer)->state,'Restored renewal executes');
same($writes+1,$state->writes,'Corrected funding permits exactly one send'); same($history,$corrected->fresh()->funding['financial_adjustments'],'Seal preserves correction audit');
$nativeBeforeReplay = nativeFinancialSnapshot($corrected);
$engine->run($corrected->id,$writer); same($writes+1,$state->writes,'Corrected renewal never replays');
same($nativeBeforeReplay, nativeFinancialSnapshot($corrected), 'Replay preserves native/financial state');
$state->expiry='2028-10-18'; $state->charge='8.00'; same('succeeded',$completion->run($corrected->id)->state,'Corrected renewal completes');
same($history,$corrected->fresh()->funding['financial_adjustments'],'Completion retains audit');
// Unsuitable fixed multiyear grace, with full native payment: refund retains holding and expiry.
// Today only Enom TEST's reviewed two-year ordinary term can be sold. It becomes
// unsuitable when execution reaches grace; do not invent an unapproved three-year offer.
$ordinaryTwoYears=static function($d)use($ports,$clock):void {
    $binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$d->server_id)->sole());
    $q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,['com'],'renew');
    $revision=static fn()=>$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision;
    $clock->instant=$clock->now()->modify('+1 second');
    $facts=new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts(['register'=>[1],'renew'=>[1],'transfer'=>[]],[],true,false,false,'synthetic',$clock->now(),$clock->now()->modify('+30 days'));
    $facts=\Paymenter\Extensions\Servers\Domains\Catalogue\TermSupport::attach('com',$facts,\Paymenter\Extensions\Servers\Domains\Catalogue\TermSupport::evidence($binding,'com'));
    $ports->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FactsObserved($binding,'com',$facts),$revision());
    $cost=new \Paymenter\Extensions\Servers\Domains\Catalogue\Cost('renew',2,true,'16.0000',\Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+30 days'));
    $ports->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding,'com',[$cost]),$revision());
};
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('refund-grace-term-placeholder.com'),true,2,beforeBill:$ordinaryTwoYears); [$engine,$writer]=$compose($b);
$clock->instant=new DateTimeImmutable('2027-10-18T12:00:01Z'); $beforeWrites=$state->writes;
same('held',$engine->run($op->id,$writer)->state,'Unsuitable paid term held'); same($beforeWrites,$state->writes,'No provider write');
$expiry=$d->provider_expires_at->format('Y-m-d');$before=$credit();
$refunds->refundHeld($staff,$op->id,'unsuitable_term');
same($before+PricingRule::minor($op->amount),$credit(),'Unsuitable fixed obligation full credit');
same('active',$d->service->fresh()->status,'Renewal refund retains active holding'); same($expiry,$d->fresh()->provider_expires_at->format('Y-m-d'),'Refund never changes expiry');
commerceReject(fn()=>DB::transaction(fn()=>RenewalCycles::next(HoldingLocks::take($d)))); // Safe replacement of a refunded unsuitable cycle remains35/36.
// Slice25 sealed typed definitely-unsent prewire evidence is independently interpreted.
[$op,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('refund-typed-prewire-placeholder.com')); [$engine,$writer]=$compose($b); $beforeWrites=$state->writes;
Operation::updated(static function($row)use($op,$d){
    if($row->id===$op->id && $row->state==='running'){ DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']); }
});
same('held',$engine->run($op->id,$writer)->state,'Real local prewire refusal'); same($beforeWrites,$state->writes,'Typed refusal before exchange');
same('definitely_unsent',$op->fresh()->attempt['execution']['send_state'],'Retained typed summary');
$attempt=$op->fresh()->attempt; $original=$op->fresh()->getRawOriginal();
foreach(['execution_null','fallback','possible_send','extra_null_fact'] as $case){
    $bad=$attempt;
    if($case==='execution_null'){$bad['execution']=null;}
    elseif($case==='fallback'){$bad['execution']['reason']='fallback';}
    elseif($case==='possible_send'){$bad['execution']['send_state']='possibly_sent';}
    else{$bad['provider_reference']=null;}
    // Privileged fixture corruption probes independent interpretation of retained typed evidence.
    Operation::withoutEvents(fn()=>$op->fresh()->update(['attempt'=>$bad]));
    commerceReject(fn()=>$refunds->refundHeld($staff,$op->id,'service_cancelled'));
    DB::table('domains_operations')->where('id',$op->id)->update($original);
}
$before=$credit(); $refunds->refundHeld($staff,$op->id,'service_cancelled');
same($before+PricingRule::minor($op->amount),$credit(),'Proven prewire refusal credited'); same($attempt,$op->fresh()->attempt,'Sealed audit untouched');
// Retired cancelled debt is never dispatched on late payment. Credit once to resolve financial conflict.
[$retired,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('refund-late-retired-placeholder.com'),false); $cycles->retireUnpaid($retired->id);
commerceReject(fn()=>(new FinancialAdjustments($clock))->append($staff,$retired->id,'mint_retired',$retired->amount,'payment_correction'));
commerceReject(fn()=>(new FinancialAdjustments($clock))->append($staff,$retired->id,'reverse_retired','-'.$retired->amount,'payment_correction'));
same('cancelled',$bill->fresh()->status,'Correction cannot fabricate a late retired payment');
same(0,InvoiceTransaction::where('invoice_id',$bill->id)->count(),'Retired debt retains no fabricated transaction');
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$replacementBill=$billing->invoice($d->id,$user); $replacement=Operation::where('invoice_id',$replacementBill->id)->sole();
App\Helpers\ExtensionHelper::addPayment($replacementBill,null,$replacement->amount);
// Native Paid/afterCommit and persisted receipt/credit bindings need the existing Paymenter SQLite fixture.
$paidOutcome=null;
\Illuminate\Support\Facades\Event::listen(\App\Events\Invoice\Paid::class,static function($event)use($bill,$presenter,$user,&$paidOutcome):void {
    if($event->invoice->id===$bill->id){DB::afterCommit(static function()use($bill,$presenter,$user,&$paidOutcome):void {$paidOutcome=$presenter->invoiceOutcome($user,(int)$bill->id);});}
});
DB::transaction(fn()=>InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>$retired->amount,'fee'=>'0.00','status'=>'succeeded']));
same(['renewal_cancelled_unpaid','pending',null,[$d->domain]],[$paidOutcome?->kind,$paidOutcome?->money,$paidOutcome?->amount,$paidOutcome?->domains],'Paid mail sees the retired renewal before any return sweep');
same(null,$presenter->invoiceOutcome($staff,(int)$bill->id),'Another owner cannot read the invoice outcome');
same(null,$presenter->invoiceOutcome($user,(int)$replacementBill->id),'An open Domains line has no closed outcome');
same('paid',$bill->fresh()->status,'Native late-payment listener retains payment and marks retired invoice paid');
commerceReject(fn()=>DB::transaction(function()use($d,$replacement){$l=HoldingLocks::take($d);RenewalFunding::requireCurrent($replacement->fresh(),$l);}));
$before=$credit(); $oldAttempt=$retired->fresh()->attempt; $paidStatus=$bill->fresh()->status;
same(['amount'=>$retired->amount,'currency'=>'USD'],$refunds->creditRejected($retired->id),'Late retired payment credited');
same($before+PricingRule::minor($retired->amount),$credit(),'Late payment refund exact');
$outcome=$presenter->invoiceOutcome($user,(int)$bill->id);
same(['credit',\Paymenter\Extensions\Servers\Domains\Services\Storefront::money($retired->amount)],[$outcome->money,$outcome->amount],'Completed legacy credit is shown only after it is recorded'); same(null,$refunds->creditRejected($retired->id),'Late refund once');
$legacy=$retired->fresh();$legacyRaw=$legacy->getRawOriginal();$legacyFunding=$legacy->funding;
unset($legacyFunding['late_credits'][0]['payments'],$legacyFunding['late_credits'][0]['to'],$legacyFunding['late_credits'][0]['allocations']);
$legacy->forceFill(['funding'=>$legacyFunding])->saveQuietly();
same('pending',$presenter->invoiceOutcome($user,(int)$bill->id)->money,'An old return without a destination or receipt allocation stays pending');
DB::table('domains_operations')->where('id',$legacy->id)->update($legacyRaw);
$domainGeneration=$retired->domain->authority_generation;
DB::table('domains_domains')->where('id',$retired->domain_id)->update(['authority_generation'=>$domainGeneration+1]);
same('renewal_cancelled_unpaid',$presenter->invoiceOutcome($user,(int)$bill->id)?->kind,'Later domain authority cannot erase an old invoice closure');
DB::table('domains_domains')->where('id',$retired->domain_id)->update(['authority_generation'=>$domainGeneration]);
same($oldAttempt,$retired->fresh()->attempt,'Original terminal unpaid proof unchanged'); same($paidStatus,$bill->fresh()->status,'Refund does not rewrite native paid status');
DB::transaction(function()use($d,$replacement,$retired){
    $l=HoldingLocks::take($d); expect(RefundPayment::retiredSettled($retired->fresh(),$l),'Exact refunded retired debt is resolved');
    same('paid_invoice',RenewalFunding::requireCurrent($replacement->fresh(),$l)['basis'],'Replacement rechecks its own funding after old debt credited');
    $cycle=$l; $cycle['operations']=$l['operations']->reject(fn($row)=>$row->id===$replacement->id);
    same(RenewalCycles::key($d).':replacement:1',RenewalCycles::next($cycle),'Settled old debt permits replacement key');
});
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);
commerceReject(fn()=>DB::transaction(function()use($d,$replacement){$l=HoldingLocks::take($d);RenewalFunding::requireCurrent($replacement->fresh(),$l);}));
same(null,$refunds->creditRejected($retired->id),'Later financial activity cannot create a second credit');
// A retired invoice with only a partial/failed payment cannot borrow the replacement payment.
[$partial,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('refund-retired-partial-placeholder.com'),false); $cycles->retireUnpaid($partial->id);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
$before=$credit();
same(['amount'=>'1.00','currency'=>'USD'],$refunds->creditRejected($partial->id),'Partial retired payment returns immediately');
same(null,$refunds->creditRejected($partial->id),'Partial return retries once');
commerceReject(fn()=>DB::transaction(fn()=>RenewalCycles::next(HoldingLocks::take($d)))); // Returning an installment does not authorize a replacement cycle.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.75','fee'=>'0.00','status'=>'succeeded']);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'-0.25','fee'=>'0.00','status'=>'succeeded']);
same(['amount'=>'0.50','currency'=>'USD'],$refunds->creditRejected($partial->id),'New money nets the reversal and earlier credits');
same($before+150,$credit(),'Incremental credit never exceeds net settlement');
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'-0.50','fee'=>'0.00','status'=>'succeeded']);
commerceReject(fn()=>$refunds->creditRejected($partial->id));
expect(DB::table('domains_obligation_alerts')->where('operation_id',$partial->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Post-credit reversal needs durable repair');
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.50','fee'=>'0.00','status'=>'succeeded']);
same(null,$refunds->creditRejected($partial->id),'Repaired net settlement creates no extra money');
same(0,DB::table('domains_obligation_alerts')->where('operation_id',$partial->id)->where('code','refund_repair')->whereNull('resolved_at')->count(),'Successful retry resolves repair');
// A changed invoice line is never guessed; restoring the exact line lets the same retry recover.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.25','fee'=>'0.00','status'=>'succeeded']);
DB::table('invoice_items')->where('id',$partial->invoice_item_id)->update(['quantity'=>2]);
commerceReject(fn()=>$refunds->creditRejected($partial->id));
expect(DB::table('domains_obligation_alerts')->where('operation_id',$partial->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Line refusal survives the failed transaction');
DB::table('invoice_items')->where('id',$partial->invoice_item_id)->update(['quantity'=>1]);
same(['amount'=>'0.25','currency'=>'USD'],$refunds->creditRejected($partial->id),'Repair retries original obligation');
// Marker failure rolls an incremental credit back together with its payment allocation.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.25','fee'=>'0.00','status'=>'succeeded']);
$failLate=true;$before=$credit();
Operation::updating(static function($row)use($partial,&$failLate):void {
    if($failLate&&$row->id===$partial->id&&count($row->funding['late_credits']??[])>count($row->getOriginal('funding')['late_credits']??[])){throw new DomainException('Synthetic late marker failure');}
});
commerceReject(fn()=>$refunds->creditRejected($partial->id));same($before,$credit(),'Crash before late marker rolls money back');
$failLate=false;$refunds->creditRejected($partial->id);same($before+25,$credit(),'Late retry returns the same incremental amount once');
// Closed-invoice returns belong to the gateway. Domains only adopts and polls the same automatic allocation.
require __DIR__.'/Support/money-receipt.php';MoneyReceiptGateway::$lost=false;
$gateway=\App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Money receipt fixture','type'=>'gateway','enabled'=>true]);
[$card,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('retired-card-partial-placeholder.com'),false);$cycles->retireUnpaid($card->id);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_partial']);
same('pending',$presenter->invoiceOutcome($user,(int)$bill->id)->money,'A capable receipt is not promised account credit before the sweep');
$before=$credit();same(null,$refunds->creditRejected($card->id),'Missing automatic return needs review, never a second allocation');
same(0,MoneyReceiptGateway::$sends,'Domains cannot send a closed-invoice return');same($before,$credit(),'Missing automatic return cannot mint credit');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$card->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Missing automatic return is durable');
MoneyReceiptGateway::automatic($bill,'receipt_partial',100,'pending');
same(null,$refunds->creditRejected($card->id),'Pending automatic return is unfinished');
MoneyReceiptGateway::complete($bill,'receipt_partial:excess');
same(['amount'=>'1.00','currency'=>'USD'],$refunds->creditRejected($card->id),'Domains observes original-method completion');
same(null,$refunds->creditRejected($card->id),'Observed automatic refund is once-only');
$outcome=$presenter->invoiceOutcome($user,(int)$bill->id);same(['returned','$1.00'],[$outcome->money,$outcome->amount],'Observed original-method completion is not reported as credit');
// An unbalanced retained plan stays pending and with staff: no finished outcome, no credit, no gateway call.
$valid=$card->fresh()->getRawOriginal('funding');$unbalanced=$card->fresh()->funding;$unbalanced['late_credits'][0]['bridge_minor']=1;
DB::table('domains_operations')->where('id',$card->id)->update(['funding'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($unbalanced))]);
same(['pending',null],[$presenter->invoiceOutcome($user,(int)$bill->id)->money,$presenter->invoiceOutcome($user,(int)$bill->id)->amount],'An unbalanced return plan is not reported as finished and has no total');
// Unreadable amounts and paid-row coverage are checked before any arithmetic: pending with no total, never an error.
DB::table('domains_operations')->where('id',$card->id)->update(['funding'=>$valid]);$readable=$card->fresh()->funding;$late=$readable['late_credits'][0];
foreach(['plan amount'=>[[...$late,'amount'=>'not-an-amount']],'payment coverage'=>[[...$late,'payments'=>[['amount_minor'=>'100']]]],
    'missing payment coverage'=>[array_diff_key($late,['payments'=>true])],'null payment coverage'=>[[...$late,'payments'=>null]],
    'late credit amount'=>[$late,['amount'=>'not-an-amount','currency'=>'USD','payments'=>[],'to'=>'account_credit','credit_id'=>null,'at'=>$late['at']]]] as $kind=>$credits){
    DB::table('domains_operations')->where('id',$card->id)->update(['funding'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode([...$readable,'late_credits'=>$credits]))]);
    $outcome=$presenter->invoiceOutcome($user,(int)$bill->id);same(['pending',null],[$outcome->money,$outcome->amount],'Unreadable '.$kind.' leaves the invoice outcome pending with no total');
    commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\ReceiptReversals::forInvoice($bill));
}
// Coverage is checked before already credited payments are summed, so an unreadable entry is a review refusal, not an error.
DB::table('domains_operations')->where('id',$card->id)->update(['funding'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode([...$readable,
    'late_credits'=>[[...$late,'payments'=>[['id'=>$late['payments'][0]['id'],'amount_minor'=>[100]]]]]]))]);
commerceReject(fn()=>DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RefundPayment::lateUncredited($card->fresh(),\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d->fresh()))));
// The credit writer checks plans before outstanding allocations, so a non-numeric allocation is a review refusal.
DB::table('domains_operations')->where('id',$card->id)->update(['funding'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode([...$readable,
    'late_credits'=>[[...$late,'allocations'=>[[...$late['allocations'][0],'amount_minor'=>'not-an-amount']]]]]))]);
commerceReject(fn()=>DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RefundPayment::lateUncredited($card->fresh(),\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d->fresh()))));
DB::table('domains_operations')->where('id',$card->id)->update(['funding'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($unbalanced))]);
$sends=MoneyReceiptGateway::$sends;same(null,$refunds->creditRejected($card->id),'An unbalanced return plan is not credited');
same([$before,$sends],[$credit(),MoneyReceiptGateway::$sends],'An unbalanced return plan moves no money and calls no gateway');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$card->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'An unbalanced return plan keeps its staff repair');
// The return writer refuses a plan whose bridge amount is missing instead of reading it as zero.
unset($unbalanced['late_credits'][0]['bridge_minor']);
DB::table('domains_operations')->where('id',$card->id)->update(['funding'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($unbalanced))]);
commerceReject(fn()=>DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RefundPayment::lateUncredited($card->fresh(),\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d->fresh()))));
DB::table('domains_operations')->where('id',$card->id)->update(['funding'=>$valid]);
same([],DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RefundPayment::lateUncredited($card->fresh(),\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d->fresh()))),'The valid plan has nothing left to credit');
// Completion can precede Domains observing the positive receipt and its matching negative native row.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.50','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_later']);
MoneyReceiptGateway::automatic($bill,'receipt_later',50);
same(['amount'=>'0.50','currency'=>'USD'],$refunds->creditRejected($card->id),'Already-completed automatic return is adopted even with zero net funding');
same(null,$refunds->creditRejected($card->id),'Later automatic return is once-only');
same($before,$credit(),'Gateway returns cannot also mint account credit');same(2,MoneyReceiptGateway::$sends,'Only the gateway produces two automatic returns');
// Mixed late money cannot credit the capable receipt while its automatic handoff is pending.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.25','fee'=>'0.00','status'=>'succeeded']);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.50','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_mixed']);
MoneyReceiptGateway::automatic($bill,'receipt_mixed',50,'pending');
same(null,$refunds->creditRejected($card->id),'Mixed late money waits for the known automatic handoff');same($before,$credit(),'No speculative credit while automatic return is pending');
MoneyReceiptGateway::complete($bill,'receipt_mixed:excess');
same(['amount'=>'0.25','currency'=>'USD'],$refunds->creditRejected($card->id),'Only unsupported money reaches the credit bridge');
same(null,$refunds->creditRejected($card->id),'Mixed bridge retry is once-only');same($before+25,$credit(),'Mixed bridge excludes all capable receipts');
$outcome=$presenter->invoiceOutcome($user,(int)$bill->id);same(['mixed','$2.25'],[$outcome->money,$outcome->amount],'Mixed cash return and bridge credit retain both destinations');
// Actual sends and unknown replies cannot be refunded, even for cancelled or stale paid obligations.
[$sent,$d,$b]=$makeRenewal(placeholderComFixtureName('refund-renew-sent-placeholder.com')); [$engine,$writer]=$compose($b); $state->lost=true;
same('uncertain',$engine->run($sent->id,$writer)->state,'Lost renewal reply remains uncertain');
$sent->refresh()->update(['state'=>'held','safe_code'=>'renewal_definitely_unsent']);
commerceReject(fn()=>$refunds->refundHeld($staff,$sent->id,'service_cancelled'));
// Three delayed retries share the same paid invoice; each consumes only exact prewire proof.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$bindings);
[$retry,$retryDomain,$retryBinding]=$makeRenewal(placeholderComFixtureName('renew-unsent-retry-placeholder.com'));$clock->instant=new DateTimeImmutable('2027-10-17T12:00:00Z');
$corrections->append($staff,$retry->id,'retry_reverse','-1.00','payment_correction');$corrections->append($staff,$retry->id,'retry_restore','1.00','payment_correction');
[$engine,$writer]=$compose($retryBinding);$retryTarget=$retry->id;
Operation::updated(static function($row)use(&$retryTarget,$retryDomain){if($row->id===$retryTarget&&$row->state==='running'){DB::table('services')->where('id',$retryDomain->service_id)->update(['status'=>'cancelled']);}});
$writes=$state->writes;$root=$retry;
for($number=0;$number<=3;$number++){
    $retryTarget=$retry->id;same('held',$engine->run($retry->id,$writer)->state,'Typed refusal held before send');$sealed=$retry->fresh()->attempt;
    DB::table('services')->where('id',$retryDomain->service_id)->update(['status'=>'active']);
    same($retry->id,\Paymenter\Extensions\Servers\Domains\Services\RenewalRetries::recover($retry->id,$clock)->id,'Retry delay is enforced');
    $clock->instant=$clock->now()->modify('+16 minutes');$next=\Paymenter\Extensions\Servers\Domains\Services\RenewalRetries::recover($retry->id,$clock);
    same($sealed,$retry->fresh()->attempt,'Retry never resets its sealed predecessor');
    if($number===3){same($retry->id,$next->id,'Our three-retry bound ends unsent automation');break;}
    expect($next->id!==$retry->id,'Fresh attempt created only from proved no send');same($root->invoice_id,$next->invoice_id,'Retry retains original paid debt');
    same($next->id,\Paymenter\Extensions\Servers\Domains\Services\RenewalRetries::recover($retry->id,$clock)->id,'Duplicate recovery converges on same attempt');
    $beforeRefundSweep=nativeFinancialSnapshot($retry);
    \Paymenter\Extensions\Servers\Domains\Services\RefundRepairs::failed($retry->id,$clock->now()->getTimestamp());
    same(null,$refunds->creditRejected($retry->id),'Proved retry predecessor has no refund obligation');
    same($beforeRefundSweep,nativeFinancialSnapshot($retry->fresh()),'Refund sweep leaves the retry payment untouched');
    same(0,DB::table('domains_obligation_alerts')->where('operation_id',$retry->id)->where('code','refund_repair')->whereNull('resolved_at')->count(),'Retry predecessor creates no false refund repair alert');
    commerceReject(fn()=>$next->update(['retry_number'=>0]));
    $duplicate=$next->getRawOriginal();unset($duplicate['id']);$duplicate['operation_key'].=':duplicate';
    try{DB::table('domains_operations')->insert($duplicate);expect(false,'Duplicate debt attempt must remain unique');}catch(\Illuminate\Database\QueryException){}
    $retry=$next;Cache::flush();
}
same($writes,$state->writes,'All local refusals leave provider writes unchanged');
// Exact old credit settlement automatically authorizes an ordinary replacement invoice, with no staff step.
[$returned,$returnedDomain]=$makeRenewal(placeholderComFixtureName('renew-credit-replace-placeholder.com'));$clock->instant=new DateTimeImmutable('2027-10-17T12:00:00Z');$returned->update(['state'=>'held','safe_code'=>'renewal_funding_or_authority']);
$refunds->refundHeld($staff,$returned->id,'service_cancelled');
\Paymenter\Extensions\Servers\Domains\Services\RenewalReplacements::recoverDomain($returnedDomain,$clock);
expect($billing->requestOptions($returnedDomain)['terms']!==[],'Settlement recovery unblocks the owner renewal preview without issuing debt');
$replacement=$billing->invoice($returnedDomain->id,$user);
expect($replacement->id!==$returned->invoice_id,'Exact full returned credit releases ordinary replacement');
same(null,$returned->fresh()->policy['renewal_replacement']['actor_id'],'System replacement audit has no invented staff actor');
same($replacement->id,$billing->invoice($returnedDomain->id,$user)->id,'Repeated invoice recovery cannot duplicate debt');
// A reversed receipt retains its Domains credit attribution even after the pooled balance was spent.
// The gateway boundary replays local observations only; no Stripe or registrar calls are installed.
class DomainsReversalGateway extends \App\Classes\Extension\Gateway {
    public static array $observations=[];
    public static bool $fail=false;
    public function pay(\App\Models\Invoice $invoice,$total){return '';}
    public function receiptReversals(\App\Models\Invoice $invoice,string $receipt):array {if(self::$fail){throw new RuntimeException('Synthetic reversal read failure');}return self::$observations[$receipt]??[];}
}
class_alias(DomainsReversalGateway::class,'Paymenter\\Extensions\\Gateways\\DomainsReversal\\DomainsReversal');
$legacyGateway=\App\Models\Gateway::create(['extension'=>'DomainsReversal','name'=>'Legacy reversal fixture','type'=>'gateway','enabled'=>true]);
[$reversed,$reversedDomain,,$reversedInvoice]=$makeRenewal(placeholderComFixtureName('reversed-credit-placeholder.com'),false);$cycles->retireUnpaid($reversed->id);
$receipt=InvoiceTransaction::create(['invoice_id'=>$reversedInvoice->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$legacyGateway->id,'transaction_id'=>'pi_domains_reversed']);
InvoiceTransaction::create(['invoice_id'=>$reversedInvoice->id,'amount'=>'0.25','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$legacyGateway->id,'transaction_id'=>'pi_domains_other']);
$refunds->creditRejected($reversed->id);
// Reproduce a previously spent account balance; attribution must come from retained credits, not this zero.
Credit::where('user_id',$user->id)->where('currency_code','USD')->sole()->update(['amount'=>'0.00']);
DomainsReversalGateway::$observations['pi_domains_reversed']=[['kind'=>'dispute','id'=>'dp_domains','status'=>'lost','needs_review'=>true,
    'invoice_id'=>(int)$reversedInvoice->id,'user_id'=>(int)$user->id,'payment_intent_id'=>'pi_domains_reversed','currency'=>'usd',
    'amount_minor'=>100,'credited_minor'=>0,'credit_balance_minor'=>0,'next_step'=>'Review the invoice and credit history.']];
DomainsReversalGateway::$observations['pi_domains_reversed'][]=DomainsReversalGateway::$observations['pi_domains_reversed'][0];
$before=$nativeMoney();$services=\App\Models\Service::orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all();$operations=Operation::orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all();
$reversals=\Paymenter\Extensions\Servers\Domains\Services\ReceiptReversals::forInvoice($reversedInvoice);
same(1,count($reversals),'Repeated receipt observations appear once in staff context');
same([100,0,0],[$reversals[0]['domains_credited_minor'],$reversals[0]['domains_unattributed_minor'],$reversals[0]['credit_balance_minor']],'Only the reversed receipt gets its exact Domains credit, including a spent zero balance');
$role->update(['permissions'=>['admin.domains.view','admin.domains.costs']]);\Illuminate\Support\Facades\Auth::setUser($staff);
$facts=\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($reversed->fresh());
expect(str_contains($facts['refund_sub'],'$1.00')&&str_contains($facts['refund_sub'],'$0.00'),'Staff credit row shows the attributed amount and current balance');
same($before,$nativeMoney(),'Reading a reversal never debits or returns money');same($services,\App\Models\Service::orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all(),'Reading a reversal never changes native service state');same($operations,Operation::orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all(),'Reading a reversal never rewrites Domains obligations');
// Native gateway dispatch and the rendered permission boundary need the existing SQLite/Filament fixture.
$reversed->refresh()->forceFill(['funding'=>[...$reversed->funding,'refund'=>['amount'=>'1.00','to'=>'original_payment','bridge_minor'=>0,
    'payments'=>[['id'=>$receipt->id,'amount_minor'=>100]],'allocations'=>[['key'=>'review']]],'receipt_return_results'=>['review'=>['state'=>'review']]]])->saveQuietly();
require_once __DIR__.'/Support/staff-panel.php';staffPanel();DomainsReversalGateway::$fail=true;
\Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$reversed->id])
    ->assertSee('Do not return it again.')->assertSee('Missing.');
$role->update(['permissions'=>['admin.domains.view']]);
\Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$reversed->id])->assertDontSee('Credit');
DomainsReversalGateway::$fail=false;
// The staff form creates the row, then saves its gateway in a second write. Rows that already exist stay fixed.
$gateway=\App\Models\Gateway::firstOrCreate(['extension'=>'Manual'],['name'=>'Manual','type'=>'gateway']);
foreach([null,$gateway] as $choice){
    $manual=InvoiceTransaction::create(['invoice_id'=>$sent->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
    $choice?$manual->gateway()->associate($choice):$manual->gateway()->dissociate();$manual->save();
    same($choice?->id,$manual->fresh()->gateway_id,'A new staff payment keeps the gateway picked in the form');
}
commerceReject(fn()=>InvoiceTransaction::findOrFail($manual->id)->update(['gateway_id'=>null]));
echo "Unsuitable renewals, independent typed prewire proof and late retired payment credit passed\n"; $nativeSuccessful=true;
