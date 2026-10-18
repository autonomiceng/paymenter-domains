<?php
// Enom 444f31c/d1392a8 tests/Enom/refunds.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Credit, InvoiceTransaction, Role, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery, FinancialAdjustments, PricingRule, Refunds};
$refunds = new Refunds($clock); $adjustments = new FinancialAdjustments($clock);
$role = Role::create(['name'=>'Refund staff','permissions'=>['admin.domains.manage','admin.invoices.update']]);
$staff = commerceUser(); $staff->update(['role_id'=>$role->id]);
$credit = static fn () => PricingRule::minor((string) (Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount') ?? '0.00'));
$ledger = static fn () => array_map(static fn ($t) => DB::table($t)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all(), ['invoices','invoice_items','invoice_transactions']);
$held = static function (string $name, bool $paid = true) use ($new) { $op=$new($name,$paid); $op->update(['state'=>'held','safe_code'=>'staff_review']); return $op->fresh(); };
$op=$held('refund-held.com'); $before=$credit(); $history=$ledger();
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
$retry=$held('refund-retry.com'); $before=$credit();
DB::unprepared("CREATE TRIGGER refuse_credit BEFORE UPDATE ON credits BEGIN SELECT RAISE(ABORT, 'synthetic credit failure'); END");
try { $refunds->refundHeld($staff,$retry->id,'customer_cancelled'); expect(false,'Synthetic credit write must fail'); }
catch (Illuminate\Database\QueryException) {}
DB::unprepared('DROP TRIGGER refuse_credit');
same('rejected',$retry->fresh()->state,'Authorized disposition survives credit failure'); same($before,$credit(),'Failed credit rolls back');
same(false,array_key_exists('refund',$retry->fresh()->funding),'No marker on failed credit');
same(1,$refunds->retryPending(),'Retry completes exactly one'); same(0,$refunds->retryPending(),'Retry idempotent');
same($before+PricingRule::minor($retry->amount),$credit(),'Retry exact credit');
// Failure writing the refund marker rolls the credit back in the same transaction.
$marker=$held('refund-marker-failure.com'); $before=$credit(); $failMarker=true;
Operation::updating(static function($row)use($marker,&$failMarker){
    if($failMarker && $row->id===$marker->id && array_key_exists('refund',$row->funding??[])){throw new DomainException('Synthetic marker failure');}
});
commerceReject(fn()=>$refunds->refundHeld($staff,$marker->id,'customer_cancelled'));
same($before,$credit(),'Marker failure rolls credit back'); same('rejected',$marker->fresh()->state,'Decision survives marker failure');
same(false,array_key_exists('refund',$marker->fresh()->funding),'Marker absent after rollback');
$failMarker=false; $refunds->creditRejected($marker->id); same($before+PricingRule::minor($marker->amount),$credit(),'Marker retry credits once');
// Local callers cannot run a credit under a partially-held/nested transaction.
$nested=$held('refund-nested.com'); DB::beginTransaction();
commerceReject(fn () => $refunds->refundHeld($staff,$nested->id,'customer_cancelled')); DB::rollBack();
same('held',$nested->fresh()->state,'Outer transaction refused');
// An enclosing workflow may schedule the owned transaction after commit; rollback never schedules credit.
$deferred=$held('refund-after-commit.com'); $before=$credit(); DB::beginTransaction();
DB::afterCommit(fn()=>$refunds->refundHeld($staff,$deferred->id,'customer_cancelled'));
same($before,$credit(),'No account credit before outer commit'); DB::commit();
same($before+PricingRule::minor($deferred->amount),$credit(),'After-commit owned transaction credits once');
$rolled=$held('refund-after-rollback.com'); $before=$credit(); DB::beginTransaction();
DB::afterCommit(fn()=>$refunds->refundHeld($staff,$rolled->id,'customer_cancelled')); DB::rollBack();
same($before,$credit(),'Outer rollback never schedules credit'); same('held',$rolled->fresh()->state,'Rolled-back decision remains open');
// Reentrant delivery is refused while the atomic credit transaction is open; paired MariaDB acceptance remains39.
$concurrent=$held('refund-concurrent.com'); $before=$credit(); $reentered=false;
Credit::saving(static function () use (&$reentered,$refunds,$concurrent) { if (!$reentered) { $reentered=true; commerceReject(fn () => $refunds->creditRejected($concurrent->id)); } });
$refunds->refundHeld($staff,$concurrent->id,'customer_cancelled');
expect($reentered,'Concurrent delivery probe reached atomic boundary'); same($before+PricingRule::minor($concurrent->amount),$credit(),'No double credit at reentrant boundary');
// Real signed native reversals, rather than funding labels, control eligibility.
$role->update(['permissions'=>['admin.domains.manage','admin.invoices.update','admin.invoice_transactions.create']]);
$unpaid=$held('refund-minted-funds.com',false); $history=$ledger();
commerceReject(fn()=>$adjustments->append($staff,$unpaid->id,'minted_funds',$unpaid->amount,'payment_correction'));
same('pending',App\Models\Invoice::find($unpaid->invoice_id)->status,'Correction cannot pay an unpaid invoice'); same($history,$ledger(),'Refused positive correction creates no payment');
$partial=$held('refund-partial.com',false); $invoice=$partial->invoice_id;
DB::table('invoices')->where('id',$invoice)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$invoice,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
commerceReject(fn () => $refunds->refundHeld($staff,$partial->id,'customer_cancelled'));
$pending=$held('refund-pending.com',false); DB::table('invoices')->where('id',$pending->invoice_id)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$pending->invoice_id,'amount'=>$pending->amount,'fee'=>'0.00','status'=>'processing']);
commerceReject(fn () => $refunds->refundHeld($staff,$pending->id,'customer_cancelled'));
$zero=$held('refund-zero-net.com'); $adjustments->append($staff,$zero->id,'zero_net_001','-'.$zero->amount,'payment_correction');
commerceReject(fn()=>$refunds->refundHeld($staff,$zero->id,'customer_cancelled'));
$adjustments->append($staff,$zero->id,'negative_net','-1.00','payment_correction');
commerceReject(fn()=>$refunds->refundHeld($staff,$zero->id,'customer_cancelled'));
$reverse=$held('refund-reversed.com'); $adjust=$adjustments->append($staff,$reverse->id,'reverse_0001','-1.00','payment_correction');
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
$mixed=$held('refund-mixed.com');
App\Models\InvoiceItem::withoutEvents(fn () => App\Models\InvoiceItem::create(['invoice_id'=>$mixed->invoice_id,'quantity'=>1,'price'=>'10.00','description'=>'Hosting']));
commerceReject(fn () => $refunds->refundHeld($staff,$mixed->id,'customer_cancelled'));
InvoiceTransaction::create(['invoice_id'=>$mixed->invoice_id,'amount'=>'12.00','fee'=>'0.00','status'=>'succeeded']);
$before=$credit();$history=$ledger(); $refunds->refundHeld($staff,$mixed->id,'customer_cancelled');
same($before+PricingRule::minor($mixed->amount),$credit(),'Overpayment/mixed invoice credits only own line'); same($history,$ledger(),'Mixed invoice preserved');
// Mixed native two-domain invoice: a refund/authorization freezes adjustments through every sibling.
$cart=commerceCart($user); commerceAdd($cart,$product,'refund-peer-a.com'); commerceAdd($cart,$product,'refund-peer-b.com');
commerceCheckout($user,$cart);
$peerA=Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain','refund-peer-a.com')->sole()->operations()->sole();
$peerB=Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain','refund-peer-b.com')->sole()->operations()->sole();
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
$contact=$new('refund-contact.com',true,false); ContactRecovery::hold($contact->id);
same('contact_details_required',$contact->fresh()->safe_code,'Real paid contact hold');
$refunds->refundHeld($staff,$contact->id,'customer_cancelled'); same('rejected',$contact->fresh()->state,'Contact recovery composes refund disposition');
commerceReject(fn () => ContactRecovery::replace($user,$contact->id,'Customer corrected contacts'));
echo "Account credit, retry, staff rights, mixed funding and contact disposition passed\n"; $nativeSuccessful=true;
