<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
use App\Models\{Gateway,Invoice,InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions;
use Paymenter\Extensions\Servers\Domains\Services\{InvoiceMoney,OwnedDomains,ReceiptReturns,Refunds};
$gateway=Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic receipt','type'=>'gateway','enabled'=>true]);
$op=$new('ledger-display-placeholder.com');$op->update(['state'=>'held','safe_code'=>'staff_review']);
DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic_display']);
MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='refunded';
$refunds=new Refunds($clock);$staff=commerceUser();$staff->update(['role_id'=>\App\Models\Role::create(['name'=>'Display fixture','permissions'=>['admin.domains.view','admin.domains.manage','admin.invoices.update']])->id]);
$refunds->refundHeld($staff,$op->id,'customer_cancelled');$op->refresh();
$before=$nativeMoney();
expect(InvoiceMoney::project((int)$op->invoice_id)['settled'],'Completed refund is settled');
same(null,OperationActions::refundPending($op,'guard_refused'),'Settled refund has no unfinished warning');
same($before,$nativeMoney(),'Display reads write no native money');
// An unmatched native reversal invalidates a completed local result. Display must keep this check visible.
InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded','transaction_id'=>'synthetic_unmatched']);
$before=$nativeMoney();
same(ReceiptReturns::REVIEW,ReceiptReturns::summary($op),'A completed result cannot hide an invoice deficit');
same(false,OwnedDomains::order($op->domain->fresh(),$op,Invoice::find($op->invoice_id))['returned']??false,'Customer projection does not claim a deficit is fully returned');
expect(OperationActions::refundPending($op,'guard_refused')!==null,'A deficit retains the staff refund check');
same($before,$nativeMoney(),'Deficit display reads write no native money');
// A kept peer leaves money on the invoice after this operation's refund finishes.
$kept=$new('ledger-kept-placeholder.com');$kept->update(['state'=>'succeeded']);
$peer=$new('ledger-returned-placeholder.com');
DB::table('domains_operations')->where('id',$peer->id)->update(['invoice_id'=>$kept->invoice_id]);$peer->refresh();
$payment=InvoiceTransaction::where('invoice_id',$kept->invoice_id)->sole();
DB::table('invoice_transactions')->where('id',$payment->id)->update(['amount'=>'5.00']);
$peer->update(['state'=>'rejected','funding'=>['refund_authorization'=>['reason'=>'customer_cancelled'],
    'refund'=>['to'=>'original_payment','bridge_minor'=>300,'allocations'=>[],'amount'=>'3.00','currency'=>'USD','payments'=>[['id'=>(int)$payment->id,'amount_minor'=>300]]]]]);
$record=InvoiceMoney::project((int)$peer->invoice_id);
same([200,false],[$record['pool'],$record['settled']],'Kept peer retains its share of the invoice');
$before=$nativeMoney();
$order=OwnedDomains::order($peer->domain->fresh(),$peer,Invoice::find($peer->invoice_id));
same(true,$order['returned'],'Completed peer credit stays returned with kept invoice money');
same('cancelled',OwnedDomains::orderStatus(['registration_rejected',null],$peer->domain,$order)[0],'Completed peer refund stays cancelled');
same(null,OperationActions::refundPending($peer,'guard_refused'),'Kept peer causes no unfinished refund warning');
\Illuminate\Support\Facades\Auth::login($staff);
$grants=\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffGrants::current();
$legacy=clone $peer;$legacy->funding=[...$peer->funding,'refund'=>[...$peer->funding['refund'],'to'=>'account_credit']];
$facts=\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($legacy,$grants);
expect(($facts['refund']??null)!==null&&$facts['refund']!==ReceiptReturns::REVIEW[0],'Staff facts retain completed account credit');
same($before,$nativeMoney(),'Shared-invoice display remains read only');
$nativeSuccessful=true;
