<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real funded checkout and audited readmission.
declare(strict_types=1);
use App\Models\{InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery,TransferInputs};
require_once __DIR__.'/staff-panel.php';
$staff=staffUser(['admin.domains.manage']);
$setTime=static function($at)use($clock):void{$clock->instant=$at;Carbon\Carbon::setTestNow($at);Carbon\CarbonImmutable::setTestNow($at);};
$scenarios=['accept','explicit-unsent','missing-code','invalid-code','late-code','cutoff-code','missing-stamp','invalid-stamp',
    'precreation-stamp','unknown-payment','unpaid','reversed','possibly-sent','sent','execution-null','cancelled','suspended','cancellation'];
foreach([$user,$staff]as$actor){
    $setTime($now);$user->properties()->where('key','phone')->update(['value'=>'']);$user->unsetRelation('properties');
    $row=$new('late-contact-'.$actor->id.'.com');$inputs->paymentAccepted($row->id);
    $inputs->submitCode($post($user,'Saved frozen transfer input'),$row->id);ContactRecovery::hold($row->id);
    $original=$row->fresh()->getRawOriginal();$originalService=Service::findOrFail($row->service_id)->getRawOriginal();
    $originalInvoice=(array)DB::table('invoices')->where('id',$row->invoice_id)->first();
    $transactionIds=DB::table('invoice_transactions')->pluck('id')->all();
    foreach($scenarios as$case){
    $setTime($now);
    // Fixture-only restoration isolates each refusal without repeating native checkout.
    DB::table('domains_operations')->where('id',$row->id)->update($original);
    DB::table('services')->where('id',$row->service_id)->update($originalService);
    DB::table('invoices')->where('id',$row->invoice_id)->update($originalInvoice);
    DB::table('invoice_transactions')->whereNotIn('id',$transactionIds)->delete();
    DB::table('service_cancellations')->where('service_id',$row->service_id)->delete();
    $user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);$user->unsetRelation('properties');
    $changes=[];$policy=$row->fresh()->policy;$payload=$row->fresh()->payload;
    switch($case){
        case 'missing-code':unset($payload['epp']);$changes['payload']=$payload;break;
        case 'invalid-code':$payload['epp']="invalid\ncode";$changes['payload']=$payload;break;
        case 'late-code':$policy['code_saved_at']=$now->modify('+30 days +1 second')->format(DATE_ATOM);break;
        case 'cutoff-code':$policy['code_saved_at']=$now->modify('+30 days')->format(DATE_ATOM);break;
        case 'missing-stamp':unset($policy['code_saved_at']);break;
        case 'invalid-stamp':$policy['code_saved_at']='2026-10-99T12:00:00+00:00';break;
        case 'precreation-stamp':$policy['code_saved_at']=$now->modify('-1 second')->format(DATE_ATOM);break;
        case 'unknown-payment':unset($policy['paid_at']);break;
        case 'unpaid':DB::table('invoices')->where('id',$row->invoice_id)->update(['status'=>'pending']);break;
        case 'reversed':InvoiceTransaction::create(['invoice_id'=>$row->invoice_id,'amount'=>'-0.01','status'=>'succeeded']);break;
        case 'explicit-unsent':$row->fresh()->update(['attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent']]);break;
        case 'possibly-sent':case 'sent':$row->fresh()->update(['attempt'=>['seal'=>'sealed','send_state'=>$case==='sent'?'sent':'possibly_sent']]);break;
        case 'execution-null':$row->fresh()->update(['attempt'=>['execution'=>null]]);break;
        case 'cancelled':case 'suspended':Service::findOrFail($row->service_id)->update(['status'=>$case]);break;
        case 'cancellation':App\Models\ServiceCancellation::create(['service_id'=>$row->service_id,'type'=>'end_of_period','reason'=>'Synthetic cancellation']);break;
    }
    // Deliberately bypass model guards only for corrupted historical-evidence fixtures.
    foreach(['policy'=>$policy,...$changes]as$key=>$value){DB::table('domains_operations')->where('id',$row->id)->update([$key=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($value))]);}
    $setTime($now->modify('+30 days +2 seconds'));$before=$row->fresh();
    $nativeBefore=nativeFinancialSnapshot($row);
    $jobsBefore=DB::table('jobs')->orderBy('id')->get()->toJson();
    $realQueue=Queue::getFacadeRoot();Queue::fake([RunOperation::class]);
    same(0,DB::transactionLevel(),'Late recovery fixture commits its own transaction');
    $accept=in_array($case,['accept','explicit-unsent'],true);
    if($accept){
        $result=ContactRecovery::replace($actor,$row->id,'Reviewed late owner contact correction');
        same('held',$result->state,'Late contact recovery stays held: '.$case);
        same('transfer_setup_review_required',$result->safe_code,'Explicit staff review gate: '.$case);
        $policyBefore=$before->policy;$policyAfter=$result->policy;unset($policyBefore['contact_readmissions'],$policyAfter['contact_readmissions']);
        same($policyBefore,$policyAfter,'Only contact audit changes policy: '.$case);
        $audit=$result->policy['contact_readmissions'];same(1,count($audit),'One readmission audit: '.$case);
        same([$actor->id,$user->id,'Reviewed late owner contact correction'],[$audit[0]['actor_id'],$audit[0]['owner_id'],$audit[0]['reason']],'Owner/staff readmission attribution: '.$case);
        foreach(['epp','registry','registry_schema','nameservers','privacy']as$key){same($before->payload[$key]??null,$result->payload[$key]??null,'Frozen independent input: '.$key);}
        same(false,$result->payload['setup_required'],'Corrected snapshot ready for review');
        same('+1.2025550100',$result->payload['contacts']['registrant']['phone'],'Fresh owner details enter by audited recovery');
        foreach(['funding','attempt','cost','started_at','completed_at','request_fingerprint','authority_generation']as$key){same($before->$key,$result->$key,'No financial/attempt authority changed: '.$key);}
        same(TransferInputs::deadline($before,$clock->now())?->format(DATE_ATOM),TransferInputs::deadline($result,$clock->now())?->format(DATE_ATOM),'Original deadline retained');
        commerceReject(fn()=>$inputs->submitCode($post($user,'Attempted late code change'),$row->id));
        same($result->getRawOriginal(),$row->fresh()->getRawOriginal(),'Attempted code replacement makes no write');
    }else{
        commerceReject(fn()=>ContactRecovery::replace($actor,$row->id,'Refused unsafe late contact correction'));
        same($before->getRawOriginal(),$row->fresh()->getRawOriginal(),'Refused matrix case is read-only: '.$case);
    }
    Queue::assertNothingPushed();Queue::swap($realQueue);
    same($jobsBefore,DB::table('jobs')->orderBy('id')->get()->toJson(),'Committed late fix never enqueues dispatch: '.$case);
    same($nativeBefore,nativeFinancialSnapshot($row),'No native/financial/dispatch side effects: '.$case);
}}
$setTime($now);
$user->properties()->where('key','phone')->update(['value'=>'']);$user->unsetRelation('properties');
$early=$new('late-prepayment-code.com',false);$inputs->submitCode($post($user,'Saved before payment'),$early->id);
App\Helpers\ExtensionHelper::addPayment($early->invoice_id,null,$early->amount);$inputs->paymentAccepted($early->id);ContactRecovery::hold($early->id);
$user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);$user->unsetRelation('properties');
$setTime($now->modify('+30 days'));
$realQueue=Queue::getFacadeRoot();Queue::fake([RunOperation::class]);
$corrected=ContactRecovery::replace($user,$early->id,'Reviewed code saved before payment');
same('held',$corrected->state,'On-time prepayment code retains the late contact hold');
same('transfer_setup_review_required',$corrected->safe_code,'On-time prepayment code allows only late contact review');
require __DIR__.'/late-contact-replay.php';
Queue::assertNothingPushed();Queue::swap($realQueue);
$setTime($now);
commerceReject(fn()=>$row->fresh()->update(['policy'=>[...$row->fresh()->policy,'code_saved_at'=>$now->modify('+1 second')->format(DATE_ATOM)]]));
$stampBefore=$row->fresh()->getRawOriginal();$without=$row->fresh()->policy;unset($without['code_saved_at']);
commerceReject(fn()=>$row->fresh()->update(['policy'=>$without]));
DB::table('domains_operations')->where('id',$row->id)->update(['policy'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($without))]);
commerceReject(fn()=>$row->fresh()->update(['policy'=>[...$without,'code_saved_at'=>null]]));
DB::table('domains_operations')->where('id',$row->id)->update($stampBefore);
