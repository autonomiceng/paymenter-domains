<?php
// Enom 444f31c/d1392a8 tests/Enom/refunds.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Credit, InvoiceTransaction, Role, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainPage;
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery, FinancialAdjustments, NativeIntentSchema, ObservationSchema, OwnedDomains, OwnerPorts, PricingRule, Refunds};
$refunds = new Refunds($clock); $adjustments = new FinancialAdjustments($clock);
$role = Role::create(['name'=>'Refund staff','permissions'=>['admin.domains.manage','admin.invoices.update']]);
$staff = commerceUser(); $staff->update(['role_id'=>$role->id]);
$credit = static fn () => PricingRule::minor((string) (Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount') ?? '0.00'));
$ledger = static fn () => array_map(static fn ($t) => DB::table($t)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all(), ['invoices','invoice_items','invoice_transactions']);
$held = static function (string $name, bool $paid = true) use ($new) { $op=$new($name,$paid); $op->update(['state'=>'held','safe_code'=>'staff_review']); return $op->fresh(); };
$op=$held('refund-held-placeholder.com'); $before=$credit(); $history=$ledger();
foreach ([[],['admin.domains.manage'],['admin.invoices.update']] as $permissions) {
    $role->update(['permissions'=>$permissions]);
    commerceReject(fn () => $refunds->refundHeld($staff,$op->id,'customer_cancelled'));
    commerceReject(fn () => $adjustments->append($staff,$op->id,'no_rights_1','-1.00','payment_correction'));
    same('held',$op->fresh()->state,'Fresh permissions refuse without disposition');
}
$role->update(['permissions'=>['admin.domains.manage','admin.invoices.update']]);
commerceReject(fn () => $adjustments->append($staff,$op->id,'native_right','-1.00','payment_correction'));
commerceReject(fn () => $refunds->refundHeld($user,$op->id,'customer_cancelled'));
commerceReject(fn () => $refunds->refundHeld($staff,$op->id,'no'));
same(['amount'=>$op->amount,'currency'=>'USD'],$refunds->refundHeld($staff,$op->id,'customer_cancelled'),'Exact held paid line credit');
same($before+PricingRule::minor($op->amount),$credit(),'Account credit exact'); same($history,$ledger(),'Native financial history unchanged');
same('cancelled',Service::find($op->service_id)->status,'Unsent acquisition cancels only its service');
same($op->domain->domain,$op->domain->fresh()->reservation_name,'Refund retains name reservation');
same($staff->id,$op->fresh()->funding['refund']['actor_id'],'Staff audit retained');
same(null,$refunds->refundHeld($staff,$op->id,'customer_cancelled'),'Staff repeated call gives no second credit');
same(null,$refunds->creditRejected($op->id),'Refund-once marker rechecked');
commerceReject(fn () => $op->fresh()->update(['funding'=>[]]));
// Failed credit keeps an authorized rejection, and retry independently checks current funding.
$retry=$held('refund-retry-placeholder.com'); $before=$credit();
DB::unprepared("CREATE TRIGGER refuse_credit BEFORE UPDATE ON credits BEGIN SELECT RAISE(ABORT, 'synthetic credit failure'); END");
try { $refunds->refundHeld($staff,$retry->id,'customer_cancelled'); expect(false,'Synthetic credit write must fail'); }
catch (Illuminate\Database\QueryException) {}
DB::unprepared('DROP TRIGGER refuse_credit');
same('rejected',$retry->fresh()->state,'Authorized disposition survives credit failure'); same($before,$credit(),'Failed credit rolls back');
same(false,array_key_exists('refund',$retry->fresh()->funding),'No marker on failed credit');
require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();NativeIntentSchema::install();ObservationSchema::install();$owner=new OwnedDomains($clock,new OwnerPorts());
$sees=static function(Operation $op)use($owner):array{$d=$owner->present($op->fresh()->domain()->firstOrFail());$notice=DomainPage::notice($d,'https://support.example.com');$expires=DomainPage::expires($d,null,'https://support.example.com');
    return [$d->status,$notice['text'],$notice['action']['label']??null,$expires['missing'],$expires['cause']??null,$expires['fix']['label']??null];};
same(['Under review','This order was cancelled. Ask us to start it again.','Contact support',true,'Order cancelled','Contact support'],$sees($retry),'No credit yet: the customer is not told it was added and can reach us');
same(1,$refunds->retryPending(),'Retry completes exactly one'); same(0,$refunds->retryPending(),'Retry idempotent');
same($before+PricingRule::minor($retry->amount),$credit(),'Retry exact credit');
same(['Cancelled','This order was cancelled. We added what you paid to account credit. You can apply it on an invoice.',null,false,null,null],$sees($retry),'Credit landed: one clear state');
// Failure writing the refund marker rolls the credit back in the same transaction.
$marker=$held('refund-marker-failure-placeholder.com'); $before=$credit(); $failMarker=true;
Operation::updating(static function($row)use($marker,&$failMarker){
    if($failMarker && $row->id===$marker->id && array_key_exists('refund',$row->funding??[])){throw new DomainException('Synthetic marker failure');}
});
commerceReject(fn()=>$refunds->refundHeld($staff,$marker->id,'customer_cancelled'));
same($before,$credit(),'Marker failure rolls credit back'); same('rejected',$marker->fresh()->state,'Decision survives marker failure');
same(false,array_key_exists('refund',$marker->fresh()->funding),'Marker absent after rollback');
$failMarker=false; $refunds->creditRejected($marker->id); same($before+PricingRule::minor($marker->amount),$credit(),'Marker retry credits once');
// Local callers cannot run a credit under a partially-held/nested transaction.
$nested=$held('refund-nested-placeholder.com'); DB::beginTransaction();
commerceReject(fn () => $refunds->refundHeld($staff,$nested->id,'customer_cancelled')); DB::rollBack();
same('held',$nested->fresh()->state,'Outer transaction refused');
// An enclosing workflow may schedule the owned transaction after commit; rollback never schedules credit.
$deferred=$held('refund-after-commit-placeholder.com'); $before=$credit(); DB::beginTransaction();
DB::afterCommit(fn()=>$refunds->refundHeld($staff,$deferred->id,'customer_cancelled'));
same($before,$credit(),'No account credit before outer commit'); DB::commit();
same($before+PricingRule::minor($deferred->amount),$credit(),'After-commit owned transaction credits once');
$rolled=$held('refund-after-rollback-placeholder.com'); $before=$credit(); DB::beginTransaction();
DB::afterCommit(fn()=>$refunds->refundHeld($staff,$rolled->id,'customer_cancelled')); DB::rollBack();
same($before,$credit(),'Outer rollback never schedules credit'); same('held',$rolled->fresh()->state,'Rolled-back decision remains open');
// Reentrant delivery is refused while the atomic credit transaction is open; paired MariaDB acceptance remains39.
$concurrent=$held('refund-concurrent-placeholder.com'); $before=$credit(); $reentered=false;
Credit::saving(static function () use (&$reentered,$refunds,$concurrent) { if (!$reentered) { $reentered=true; commerceReject(fn () => $refunds->creditRejected($concurrent->id)); } });
$refunds->refundHeld($staff,$concurrent->id,'customer_cancelled');
expect($reentered,'Concurrent delivery probe reached atomic boundary'); same($before+PricingRule::minor($concurrent->amount),$credit(),'No double credit at reentrant boundary');
// Real signed native reversals, rather than funding labels, control eligibility.
$role->update(['permissions'=>['admin.domains.manage','admin.invoices.update','admin.invoice_transactions.create']]);
$unpaid=$held('refund-minted-funds-placeholder.com',false); $history=$ledger();
commerceReject(fn()=>$adjustments->append($staff,$unpaid->id,'minted_funds',$unpaid->amount,'payment_correction'));
same('pending',App\Models\Invoice::find($unpaid->invoice_id)->status,'Correction cannot pay an unpaid invoice'); same($history,$ledger(),'Refused positive correction creates no payment');
$partial=$held('refund-partial-placeholder.com',false); $invoice=$partial->invoice_id;
DB::table('invoices')->where('id',$invoice)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$invoice,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
commerceReject(fn () => $refunds->refundHeld($staff,$partial->id,'customer_cancelled'));
$pending=$held('refund-pending-placeholder.com',false); DB::table('invoices')->where('id',$pending->invoice_id)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$pending->invoice_id,'amount'=>$pending->amount,'fee'=>'0.00','status'=>'processing']);
commerceReject(fn () => $refunds->refundHeld($staff,$pending->id,'customer_cancelled'));
$zero=$held('refund-zero-net-placeholder.com'); $adjustments->append($staff,$zero->id,'zero_net_001','-'.$zero->amount,'payment_correction');
commerceReject(fn()=>$refunds->refundHeld($staff,$zero->id,'customer_cancelled'));
$adjustments->append($staff,$zero->id,'negative_net','-1.00','payment_correction');
commerceReject(fn()=>$refunds->refundHeld($staff,$zero->id,'customer_cancelled'));
$reverse=$held('refund-reversed-placeholder.com'); $adjust=$adjustments->append($staff,$reverse->id,'reverse_0001','-1.00','payment_correction');
same($adjust->id,$adjustments->append($staff,$reverse->id,'reverse_0001','-1.00','payment_correction')->id,'Staff adjustment retry idempotent');
commerceReject(fn () => $adjustments->append($staff,$reverse->id,'reverse_0001','-2.00','payment_correction'));
commerceReject(fn () => $adjust->update(['amount'=>'-2.00'])); commerceReject(fn () => $adjust->fresh()->delete());
commerceReject(fn () => $refunds->refundHeld($staff,$reverse->id,'customer_cancelled'));
same('held',$reverse->fresh()->state,'Insufficient signed net remains held');
commerceReject(fn()=>$adjustments->append($staff,$reverse->id,'over_restore','1.01','payment_correction'));
$adjustments->append($staff,$reverse->id,'restore_0001','1.00','payment_correction');
commerceReject(fn()=>$adjustments->append($staff,$reverse->id,'second_mint_1','0.01','payment_correction'));
$refunds->refundHeld($staff,$reverse->id,'customer_cancelled');
commerceReject(fn () => $adjustments->append($staff,$reverse->id,'after_refund','1.00','payment_correction'));
// Mixed invoices refund just the discounted native domain line, preserving hosting and other lines.
$mixed=$held('refund-mixed-placeholder.com');
App\Models\InvoiceItem::withoutEvents(fn () => App\Models\InvoiceItem::create(['invoice_id'=>$mixed->invoice_id,'quantity'=>1,'price'=>'10.00','description'=>'Hosting']));
commerceReject(fn () => $refunds->refundHeld($staff,$mixed->id,'customer_cancelled'));
InvoiceTransaction::create(['invoice_id'=>$mixed->invoice_id,'amount'=>'12.00','fee'=>'0.00','status'=>'succeeded']);
$before=$credit();$history=$ledger(); $refunds->refundHeld($staff,$mixed->id,'customer_cancelled');
same($before+PricingRule::minor($mixed->amount),$credit(),'Overpayment/mixed invoice credits only own line'); same($history,$ledger(),'Mixed invoice preserved');
// Mixed native two-domain invoice: a refund/authorization freezes adjustments through every sibling.
$cart=commerceCart($user); commerceAdd($cart,$product,placeholderComFixtureName('refund-peer-a-placeholder.com')); commerceAdd($cart,$product,placeholderComFixtureName('refund-peer-b-placeholder.com'));
commerceCheckout($user,$cart);
$peerA=Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain','refund-peer-a-placeholder.com')->sole()->operations()->sole();
$peerB=Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain','refund-peer-b-placeholder.com')->sole()->operations()->sole();
same($peerA->invoice_id,$peerB->invoice_id,'Native two-domain shared invoice');
App\Helpers\ExtensionHelper::addPayment($peerA->invoice_id,null,PricingRule::decimal(PricingRule::minor($peerA->amount)+PricingRule::minor($peerB->amount)));
$peerA->update(['state'=>'held','safe_code'=>'staff_review']);
DB::unprepared("CREATE TRIGGER refuse_peer_credit BEFORE UPDATE ON credits BEGIN SELECT RAISE(ABORT, 'synthetic peer credit failure'); END");
try{$refunds->refundHeld($staff,$peerA->id,'customer_cancelled');expect(false,'Synthetic peer credit failure');}catch(Illuminate\Database\QueryException){}
DB::unprepared('DROP TRIGGER refuse_peer_credit');
commerceReject(fn()=>$adjustments->append($staff,$peerB->id,'sibling_auth','-1.00','payment_correction'));
$refunds->creditRejected($peerA->id);
$history=$ledger(); commerceReject(fn()=>$adjustments->append($staff,$peerB->id,'sibling_edit','-1.00','payment_correction'));
same($history,$ledger(),'Sibling cannot change refunded invoice funding');
// The untouched sibling still refunds its own exact line without rewriting invoice funding.
$peerB->update(['state'=>'held','safe_code'=>'staff_review']); $before=$credit(); $refunds->refundHeld($staff,$peerB->id,'customer_cancelled');
same($before+PricingRule::minor($peerB->amount),$credit(),'Sibling line refunds once independently');
// A contact hold must pass through its existing recovery authority.
$contact=$new(placeholderComFixtureName('refund-contact-placeholder.com'),true,false); ContactRecovery::hold($contact->id);
same('contact_details_required',$contact->fresh()->safe_code,'Real paid contact hold');
$refunds->refundHeld($staff,$contact->id,'customer_cancelled'); same('rejected',$contact->fresh()->state,'Contact recovery composes refund disposition');
commerceReject(fn () => ContactRecovery::replace($user,$contact->id,'Customer corrected contacts'));
// Native gateway dispatch uses an optional receipt port; the fake persists acceptance before losing its reply.
require __DIR__.'/Support/money-receipt.php';
$gateway=\App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Money receipt fixture','type'=>'gateway','enabled'=>true]);
$original=$held('original-payment-refund-placeholder.com');
DB::table('invoice_transactions')->where('invoice_id',$original->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'receipt_synthetic_original']);
$before=$credit();try{$refunds->refundHeld($staff,$original->id,'customer_cancelled');expect(false,'Lost refund reply must surface');}catch(RuntimeException){}
same('original_payment',$original->fresh()->funding['refund']['to'],'Original method plan survives reply loss');
same($before,$credit(),'Unknown gateway result cannot also issue account credit');
$refunds->creditRejected($original->id);$refunds->creditRejected($original->id);
same(1,MoneyReceiptGateway::$sends,'Restart and repeated ticks reuse the one allocation');
same($before,$credit(),'Confirmed gateway refund leaves account credit alone');
// A bank can fail a previously succeeded refund. The gateway reclassifies that same return as credit.
$key=array_key_last(MoneyReceiptGateway::$returns);$sends=MoneyReceiptGateway::$sends;
MoneyReceiptGateway::$returns[$key]['state']='review';
$refunds->creditRejected($original->id);same('review',array_values($original->fresh()->funding['receipt_return_results'])[0]['state'],'Disputed late failure remains visible without another payment');
$negative=InvoiceTransaction::where('transaction_id',MoneyReceiptGateway::$returns[$key]['key'])->sole();$nativeReturn=$negative->getAttributes();
commerceReject(fn()=>$negative->update(['is_credit_transaction'=>true]));
MoneyReceiptGateway::credit(App\Models\Invoice::findOrFail($original->invoice_id),$key);
$afterReturn=$negative->fresh()->getAttributes();same($nativeReturn,array_replace($afterReturn,['is_credit_transaction'=>$nativeReturn['is_credit_transaction']]),'Owned event-free reclassification changes only its flag while the general financial guard remains active');
same(['amount'=>$original->amount,'currency'=>'USD'],$refunds->creditRejected($original->id),'Resolved gateway review is observed without a new payment');
same('credited',array_values($original->fresh()->funding['receipt_return_results'])[0]['state'],'Gateway credit replaces the historical refunded outcome');
same($sends,MoneyReceiptGateway::$sends,'Late refund failure never allocates another return');
$before+=PricingRule::minor($original->amount);$refunds->creditRejected($original->id);same($before,$credit(),'Gateway fallback is retained once, without a Domains credit');
// A second worker may observe the plan before the first worker records its gateway result.
$interleaved=$held('interleaved-refund-placeholder.com');
DB::table('invoice_transactions')->where('invoice_id',$interleaved->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'receipt_synthetic_interleaved']);
MoneyReceiptGateway::$onAllocated=static fn()=>$refunds->creditRejected($interleaved->id);
$refunds->refundHeld($staff,$interleaved->id,'customer_cancelled');$refunds->creditRejected($interleaved->id);
same(2,MoneyReceiptGateway::$sends,'Overlapping workers share the exact gateway allocation');same($before,$credit(),'Interleaved refund cannot fall back to credit');
// Multiple failed lines share a receipt: earlier exact return reversals preserve coverage for the remaining line.
$cart=commerceCart($user);
foreach(['shared-return-one-placeholder.com','shared-return-two-placeholder.com'] as $name){commerceAdd($cart,$product,$name);}
commerceCheckout($user,$cart);
$shared=Operation::whereHas('domain',fn($q)=>$q->whereIn('domain',['shared-return-one-placeholder.com','shared-return-two-placeholder.com']))->orderBy('id')->get();
same(2,$shared->count(),'Native checkout issued both failed lines');same($shared[0]->invoice_id,$shared[1]->invoice_id,'Lines share native invoice');
DB::table('invoices')->where('id',$shared[0]->invoice_id)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$shared[0]->invoice_id,'amount'=>PricingRule::decimal($shared->sum(fn($op)=>PricingRule::minor($op->amount))),'fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_synthetic_shared']);
foreach($shared as $line){$line->update(['state'=>'held','safe_code'=>'staff_review']);$refunds->refundHeld($staff,$line->id,'customer_cancelled');$refunds->creditRejected($line->id);}
same(4,MoneyReceiptGateway::$sends,'Both failed lines return once on distinct allocations');same($before,$credit(),'Shared original refund never adds bridge credit');
foreach(['reserved','pending','review'] as $outcome){
    MoneyReceiptGateway::$outcome=$outcome;$waiting=$held($outcome.'-return-placeholder.com');$sends=MoneyReceiptGateway::$sends;
    DB::table('invoice_transactions')->where('invoice_id',$waiting->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'receipt_synthetic_'.$outcome]);
    same(null,$refunds->refundHeld($staff,$waiting->id,'customer_cancelled'),'Unfinished gateway allocation returns no completion');
    $key=array_key_last(MoneyReceiptGateway::$returns);
    $refunds->creditRejected($waiting->id);same($sends+1,MoneyReceiptGateway::$sends,'Unfinished retries preserve one gateway allocation');same($before,$credit(),'Unfinished allocation cannot create bridge credit');
    if($outcome==='pending'){
        MoneyReceiptGateway::$onStatus=function()use($waiting,$key,$refunds):void {
            MoneyReceiptGateway::complete(App\Models\Invoice::findOrFail($waiting->invoice_id),$key);$refunds->creditRejected($waiting->id);
        };
        $refunds->creditRejected($waiting->id);
        same('refunded',array_values($waiting->fresh()->funding['receipt_return_results'])[0]['state'],'Stale concurrent pending reply cannot overwrite completed return');
    }else{
        MoneyReceiptGateway::complete(App\Models\Invoice::findOrFail($waiting->invoice_id),$key);
        same(['amount'=>$waiting->amount,'currency'=>'USD'],$refunds->creditRejected($waiting->id),'Completed original allocation is recovered');
    }
    same(null,$refunds->creditRejected($waiting->id),'Recovered completion returns once');
}
// A completed status without its exact native reversal cannot clear the obligation or authorize another credit.
MoneyReceiptGateway::$outcome='pending';$broken=$held('missing-reversal-placeholder.com');
DB::table('invoice_transactions')->where('invoice_id',$broken->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'receipt_missing_reversal']);
$refunds->refundHeld($staff,$broken->id,'customer_cancelled');$key=array_key_last(MoneyReceiptGateway::$returns);
MoneyReceiptGateway::$returns[$key]['state']='refunded';
commerceReject(fn()=>$refunds->creditRejected($broken->id));
MoneyReceiptGateway::complete(App\Models\Invoice::findOrFail($broken->invoice_id),$key);
same(['amount'=>$broken->amount,'currency'=>'USD'],$refunds->creditRejected($broken->id),'Restored native reversal repairs the same allocation');
same(null,$refunds->creditRejected($broken->id),'Native reversal repair never duplicates money');
MoneyReceiptGateway::$outcome='refunded';
echo "Account credit, retry, staff rights, mixed funding and contact disposition passed\n"; $nativeSuccessful=true;
