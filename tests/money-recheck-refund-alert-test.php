<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
require __DIR__.'/Support/money-receipt.php';
use App\Models\{Gateway,Invoice,InvoiceTransaction};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,ListNote,StaffDomainInputs};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,HoldingLocks,InvoiceMoney,OperationTerminalProof,PricingRule,Refunds};

AcquisitionRetirement::boot();
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.invoices.update']);
Auth::login($staff);
MoneyReceiptGateway::$lost=false;
$gateway=Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic receipt','type'=>'gateway','enabled'=>true]);
$op=$new(placeholderComFixtureName('money-recheck-refund-placeholder.com'));
DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'receipt_recheck_original']);
[$claimed]=$claims->claim($op->id);
same('held',$claims->finish($claimed)->state,'Completed unsealed worker did not send');
OperationTerminalProof::encode($claims,$op->id);
$refunds=new Refunds($clock);
$refunds->creditRejected($op->id);
$refunds->creditRejected($op->id);
$op=$op->fresh();$domain=$op->domain->fresh();
same(true,OperationTerminalProof::safe($op,$domain),'Original registration is terminal and definitely unsent');
same('original_payment',$op->funding['refund']['to'],'Refund used original payment');
same(['refunded'],array_column($op->funding['receipt_return_results'],'state'),'Original refund completed');
same(false,isset($op->policy['native_acquisition_closure']),'Ordinary refund has no native closure snapshot');
same(false,isset($op->funding['refund']['allocations'][0]['gateway_key']),'Ordinary refund is not a gateway-owned excess return');
$rows=InvoiceTransaction::where('invoice_id',$op->invoice_id)->orderBy('id')->get();
same([PricingRule::minor($op->amount),-PricingRule::minor($op->amount)],$rows->map(fn($r)=>PricingRule::minor($r->amount))->all(),'Only original payment and its exact reversal exist');
$record=DB::transaction(fn()=>InvoiceMoney::load(HoldingLocks::take($domain),(int)$op->invoice_id));
same([0,true],[$record['pool'],$record['settled']],'Independent money record proves zero unsettled money');
$money=$nativeMoney();
$alerts=static fn()=>DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','acquisition_late_money')->whereNull('resolved_at');
$retirement->inspect($domain->id);$first=$alerts()->count();
$retirement->inspect($domain->id);$second=$alerts()->count();
$inputs=StaffDomainInputs::for([$domain->fresh()])[$domain->id];
$status=DomainFacts::status($domain->fresh(),$inputs);$note=DomainFacts::note($status,null);
echo 'REFUND_RECHECK '.json_encode(['pool'=>$record['pool'],'settled'=>$record['settled'],'invoice_status'=>Invoice::find($op->invoice_id)->status,
    'native_amounts'=>$rows->pluck('amount')->all(),'open_alerts_first'=>$first,'open_alerts_repeat'=>$second,
    'staff_late'=>$inputs['late'],'staff_alert_codes'=>array_column($inputs['alerts'],'code'),
    'detail_step'=>$status['step'],'list_note'=>ListNote::clause($note['text'],$status['request'])],JSON_THROW_ON_ERROR)."\n";
same($money,$nativeMoney(),'Inspections and staff projections write no money');
same(1,MoneyReceiptGateway::$sends,'No repeated refund was sent');
same([0,0,false],[$first,$second,$inputs['late']],'Settled original refund must not require late-payment review');

// A successful peer's retained money must not reopen this completed return.
$kept=$new('recheck-kept-placeholder.com');$kept->update(['state'=>'succeeded']);
DB::table('domains_operations')->where('id',$kept->id)->update(['invoice_id'=>$op->invoice_id]);
$payment=InvoiceTransaction::where('invoice_id',$op->invoice_id)->where('amount','>','0')->sole();
DB::table('invoice_transactions')->where('id',$payment->id)->update(['amount'=>PricingRule::decimal(PricingRule::minor($payment->amount)+200)]);
$record=InvoiceMoney::project((int)$op->invoice_id);
same([200,false],[$record['pool'],$record['settled']],'Shared invoice retains the kept peer payment');
$retirement->inspect($domain->id);$retirement->inspect($domain->id);
same(0,$alerts()->count(),'A completed shared-invoice refund raises no late-money alert');

// Settlement also closes false rows retained by an earlier build.
\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($domain,'acquisition_late_money',$clock->now()->getTimestamp(),(int)$op->id,'synthetic_old_projection');
$retirement->inspect($domain->id);same(0,$alerts()->count(),'A settled prefilter clears an already-open false alert');
$pending=InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'0.01','fee'=>'0.00','status'=>'processing']);
$retirement->inspect($domain->id);$active=$alerts()->sole();
$identity=InvoiceMoney::project((int)$op->invoice_id)['attentionFingerprint'];
$hold=DB::table('domains_obligation_alerts')->insertGetId(['alert_key'=>hash('sha256','synthetic_attention_owner_hold'),'domain_id'=>$domain->id,'generation'=>1,
    'code'=>'account_money_hold','first_seen'=>1,'last_seen'=>1]);
same($identity,InvoiceMoney::project((int)$op->invoice_id)['attentionFingerprint'],'An unrelated owner hold never changes attention identity');
DB::table('invoice_transactions')->where('id',$pending->id)->update(['status'=>'failed']);$retirement->inspect($domain->id);
same(0,$alerts()->count(),'Automatic settlement resolves pending-payment attention');
DB::table('invoice_transactions')->where('id',$pending->id)->update(['status'=>'processing']);$retirement->inspect($domain->id);
same((int)$active->id,(int)$alerts()->sole()->id,'The same evidence reopens after automatic resolution');
$staff->role->update(['permissions'=>['admin.domains.view','admin.domains.manage-policy','admin.invoices.view',\Paymenter\Extensions\Servers\Domains\Services\AcquisitionPaymentReviews::PERMISSION]]);
$reviews=new \Paymenter\Extensions\Servers\Domains\Services\AcquisitionPaymentReviews($clock);
$reviews->review($staff,(int)$domain->id,$reviews->ticket($staff,(int)$domain->id),'Reviewed the same pending payment',true);
$retirement->inspect($domain->id);same(0,$alerts()->count(),'Only an audited staff acknowledgement suppresses the same evidence');
$nativeSuccessful=true;
