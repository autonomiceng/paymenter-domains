<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
AcquisitionRetirement::boot();
// Native gateway reversals are expected closure activity, not new late money or a second Domains return.
require __DIR__.'/Support/money-receipt.php';MoneyReceiptGateway::$lost=false;
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Money receipt fixture','type'=>'gateway','enabled'=>true]);
$automatic=$new(placeholderComFixtureName('closed-auto-return-placeholder.com'),paid:false);$cancel($automatic);$retirement->inspect($automatic->domain_id);
$bill=App\Models\Invoice::findOrFail($automatic->invoice_id);
App\Models\InvoiceTransaction::withoutEvents(fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_closed_auto']));
MoneyReceiptGateway::automatic($bill,'receipt_closed_auto',100);
$returns=app(Paymenter\Extensions\Servers\Domains\Services\Refunds::class);$account=Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$automatic->server_id)->firstOrFail();
$observed=$returns->creditRetired($account);
expect(in_array($automatic->domain_id,$observed,true),'Closed acquisition observes the gateway return');
same([],$returns->creditRetired($account),'Closed acquisition repeats without another return');
$returns->creditRetired($account);$retirement->inspect($automatic->domain_id);
expect(UnpaidAcquisitionProof::terminal($automatic->fresh(),$automatic->domain->fresh()),'Return results preserve retained no-send proof');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$automatic->domain_id)->whereIn('code',['refund_repair','acquisition_late_money'])->whereNull('resolved_at')->count(),'Expected gateway reversal creates no new repair step');
same(1,MoneyReceiptGateway::$sends,'Gateway owns the only closed acquisition return');
// The closed-order sweep checks a malformed retained plan before outstanding allocations: staff repair, no error, money or gateway call.
$staysLogs=[];\Illuminate\Support\Facades\Log::listen(static function($event)use(&$staysLogs,$automatic):void {if($event->message==='Domains late payment stays with staff.'&&($event->context['operation_id']??null)===(int)$automatic->id){$staysLogs[]=$event->context;}});
$validRaw=$automatic->fresh()->getRawOriginal('funding');$bad=$automatic->fresh()->funding;$bad['late_credits'][0]['allocations'][0]=[...$bad['late_credits'][0]['allocations'][0],'amount_minor'=>'not-an-amount','key'=>'domains:closed:unobserved'];
DB::table('domains_operations')->where('id',$automatic->id)->update(['funding'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($bad))]);
$rows=static fn(string $table)=>DB::table($table)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all();$money=[$rows('credits'),$rows('invoice_transactions')];$calls=[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends];
same([],$returns->creditRetired($account),'A malformed closed-order plan is not reported as credited');
same([],$staysLogs,'A malformed closed-order plan is checked before allocation arithmetic');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$automatic->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'A malformed closed-order plan keeps a staff repair');
same($money[0],$rows('credits'),'A malformed closed-order plan adds no credit');
same($money[1],$rows('invoice_transactions'),'A malformed closed-order plan writes no payment row');
same($calls,[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends],'A malformed closed-order plan calls no gateway');
// An account-credit return with unreadable coverage is checked before the sweep reads what it credited: staff repair, no error.
Paymenter\Extensions\Servers\Domains\Services\RefundRepairs::resolved((int)$automatic->id,$clock->now()->getTimestamp());$creditOnly=$automatic->fresh()->funding;
$creditOnly['late_credits']=[['payments'=>[['id'=>(int)App\Models\InvoiceTransaction::where('transaction_id','receipt_closed_auto')->value('id'),'amount_minor'=>[100]]],
    'amount'=>'1.00','currency'=>'USD','to'=>'account_credit','credit_id'=>null,'at'=>$clock->now()->format(DATE_ATOM)]];
DB::table('domains_operations')->where('id',$automatic->id)->update(['funding'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($creditOnly))]);
same([],$returns->creditRetired($account),'Unreadable account-credit coverage is not reported as credited');
same([DomainException::class],array_column($staysLogs,'exception_class'),'Unreadable account-credit coverage is a review refusal, not an error');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$automatic->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Unreadable account-credit coverage keeps a staff repair');
same([$money[0],$money[1]],[$rows('credits'),$rows('invoice_transactions')],'Unreadable account-credit coverage moves no money');
same($calls,[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends],'Unreadable account-credit coverage calls no gateway');
DB::table('domains_operations')->where('id',$automatic->id)->update(['funding'=>$validRaw]);
$nativeSuccessful=true;
