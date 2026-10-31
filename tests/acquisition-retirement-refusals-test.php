<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
// No event, raw cancelled fixtures model missed wake-ups and privileged bulk changes.
foreach(['partial','processing','reversed','sent','null_execution','claim','provider','sibling']as$case){
    $op=$new(placeholderComFixtureName('unpaid-'.str_replace('_','-',$case).'-placeholder.com'),paid:false);
    if(in_array($case,['partial','processing','reversed'],true)){
        App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$case==='reversed'?'-1.00':'1.00','fee'=>'0.00','status'=>$case==='processing'?'processing':'succeeded']);
    }elseif($case==='sent'){$op->update(['attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent']]);}
    elseif($case==='null_execution'){$op->update(['attempt'=>['execution'=>null]]);}
    elseif($case==='claim'){$op->update(['started_at'=>$clock->now(),'request_fingerprint'=>str_repeat('a',64),'attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent','claim_key'=>str_repeat('a',32)]]);}
    elseif($case==='provider'){$op->domain->update(['provider_order_id'=>'synthetic-provider']);}
    else{Operation::create(['domain_id'=>$op->domain_id,'service_id'=>$op->service_id,'server_id'=>$op->server_id,'registrar_key'=>$op->registrar_key,'account_fingerprint'=>$op->account_fingerprint,'authority_generation'=>1,'operation_key'=>'other:'.$op->id,'action'=>'renew','term'=>1,'state'=>'queued','amount'=>'1.00','currency'=>'USD']);}
    $cancel($op);$retirement->inspect($op->domain_id);same($op->domain->domain,$op->domain->fresh()->reservation_name,$case.' retains name');
    same(1,DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','acquisition_retirement_review')->count(),$case.' durable review alert');
    commerceReject(fn()=>$retirement->retire($op->domain_id,$staff,$ticket($op),'Adversarial staff retirement'));
}
$oldPartial=$new(placeholderComFixtureName('unpaid-old-partial-placeholder.com'),paid:false);
App\Models\InvoiceTransaction::create(['invoice_id'=>$oldPartial->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);
DB::table('services')->where('id',$oldPartial->service_id)->update(['created_at'=>$clock->now()->modify('-8 days')]);
$retirement->inspect($oldPartial->domain_id);same(1,DB::table('domains_obligation_alerts')->where('domain_id',$oldPartial->domain_id)->where('code','acquisition_retirement_review')->count(),'Old partial pending acquisition gets durable review without cancellation');
$transferOp=$new(placeholderComFixtureName('unpaid-transfer-placeholder.com'),paid:false,action:'transfer');$cancel($transferOp);$retirement->inspect($transferOp->domain_id);same('rejected',$transferOp->fresh()->state,'Unpaid transfer closes with same proof');same(null,$transferOp->domain->fresh()->reservation_name,'Unpaid transfer releases name');
$open=$new(placeholderComFixtureName('unpaid-open-invoice-placeholder.com'),paid:false);DB::table('services')->where('id',$open->service_id)->update(['status'=>'cancelled']);$retirement->inspect($open->domain_id);
same('unpaid-open-invoice-placeholder.com',$open->domain->fresh()->reservation_name,'Incomplete native cancellation retains name');same(1,DB::table('domains_obligation_alerts')->where('domain_id',$open->domain_id)->where('code','acquisition_retirement_review')->whereNull('resolved_at')->count(),'Open invoice cancellation alerts staff');
$retirement->retire($open->domain_id,$staff,$ticket($open),'Finish reviewed unpaid cancellation');same(0,DB::table('domains_obligation_alerts')->where('domain_id',$open->domain_id)->where('code','acquisition_retirement_review')->whereNull('resolved_at')->count(),'Proven retirement resolves its earlier incomplete review');
$configured=$new(placeholderComFixtureName('unpaid-configured-age-placeholder.com'),paid:false);App\Models\InvoiceTransaction::create(['invoice_id'=>$configured->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);DB::table('services')->where('id',$configured->service_id)->update(['created_at'=>$clock->now()->modify('-8 days')]);
$cancelDays=config('settings.cronjob_order_cancel',7);config(['settings.cronjob_order_cancel'=>10]);$retirement->inspect($configured->domain_id);same(0,DB::table('domains_obligation_alerts')->where('domain_id',$configured->domain_id)->count(),'Configured native cancellation delay is respected');config(['settings.cronjob_order_cancel'=>4]);$retirement->inspect($configured->domain_id);same(1,DB::table('domains_obligation_alerts')->where('domain_id',$configured->domain_id)->count(),'Configured shorter delay reviews old partial money');config(['settings.cronjob_order_cancel'=>$cancelDays]);
echo "Unpaid retirement money, send, sibling and age refusals passed.\n";$nativeSuccessful=true;
