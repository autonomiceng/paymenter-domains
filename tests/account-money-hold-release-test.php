<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/money-receipt.php';
use App\Models\{Audit,Gateway,Invoice};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountMoneyHolds,DisputeRecoveries,InvoiceMoney,ReminderSchema,StaffOperationReview};
class UnreadableHoldGateway extends MoneyReceiptGateway {
    public function receiptLedger(Invoice $invoice):array { throw new RuntimeException('Synthetic unreadable evidence'); }
}
class_alias(UnreadableHoldGateway::class,'Paymenter\\Extensions\\Gateways\\UnreadableHold\\UnreadableHold');
ReminderSchema::install();
$op=$new('unreadable-hold-placeholder.com');
$gateway=Gateway::create(['extension'=>'UnreadableHold','name'=>'Synthetic unreadable receipt','type'=>'gateway','enabled'=>true]);
DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic_unreadable_hold']);
$alert=DB::table('domains_obligation_alerts')->insertGetId(['alert_key'=>DisputeRecoveries::key('account_money_hold','synthetic_unreadable'),
    'domain_id'=>$op->domain_id,'operation_id'=>$op->id,'generation'=>$op->authority_generation,'code'=>'account_money_hold','first_seen'=>1,'last_seen'=>1]);
$staff=staffUser(['admin.domains.view']);
commerceReject(fn()=>AccountMoneyHolds::release($staff,(int)$alert));
expect(InvoiceMoney::ownerHeld((int)$user->id),'Unauthorized release retains the account hold');
same(0,Audit::where('event','account_money_hold_released')->count(),'Unauthorized release writes no invoice Audit');
$staff->role->update(['permissions'=>['admin.domains.view','admin.domains.manage',StaffOperationReview::permission('release'),'admin.invoices.update']]);
$before=$nativeMoney();
AccountMoneyHolds::release($staff,(int)$alert);
expect(!InvoiceMoney::ownerHeld((int)$user->id),'Staff release succeeds even while receipt evidence is unreadable');
$audit=Audit::where('event','account_money_hold_released')->sole();
same([(new Invoice)->getMorphClass(),(int)$op->invoice_id,(int)$staff->id],[$audit->auditable_type,(int)$audit->auditable_id,(int)$audit->user_id],'Native Audit identifies the invoice and releasing actor');
same(true,$audit->new_values['evidence_unreadable']??false,'Audit records that dispute evidence was unreadable');
same((int)$alert,$audit->new_values['alert_id']??null,'Audit retains the released hold identity without dispute evidence');
same($before,$nativeMoney(),'Staff release changes no native financial rows');
commerceReject(fn()=>AccountMoneyHolds::release($staff,(int)$alert));
same(1,Audit::where('event','account_money_hold_released')->count(),'Repeated release retains one Audit');
$nativeSuccessful=true;
