<?php
// Native renewal refunds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$renewalFixtureNames=['refund-late-retired'];
require __DIR__.'/Support/renewal-refund.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
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
same([],array_keys($registrations),'Every independent refund scenario ran');
echo "Retired renewal closure and replacement funding passed\n"; $nativeSuccessful=true;
