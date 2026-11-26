<?php
// Fresh auto-renew-off proof after one sealed write; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationSchema;
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAdmission;
require_once __DIR__.'/staff-panel.php';require_once __DIR__.'/renewal-attempts.php';
$financial=nativeFinancialSnapshot($renewal);$originalAttempt=renewalRecord($renewal);
expect($originalAttempt['attempt']!==null&&$originalAttempt['reply']!==null,'The acknowledged renewal has a sealed attempt and reply');
foreach(['on','missing','invalid','outage','late'] as $case){
    // Every check counts toward the read limit; each case is the first check, so staff can still ask for a fresh one.
    $fresh=$renewal->fresh();$fresh->update(['policy'=>array_diff_key($fresh->policy,['renewal_polls'=>true])]);
    $wire->auto=$case==='on'?'Yes':($case==='invalid'?'unknown':'No');
    $wire->omit=$case==='missing'?['auto_renew']:[];
    if($case==='outage'){
        $failDomain=static function($command)use(&$failDomain,$wire){
            if($command==='getDomainInfo'){throw new RuntimeException('Synthetic renewal readback outage');}
            $wire->during=$failDomain;
        };$wire->during=$failDomain;
    }
    $beforeRead=$clock->instant;
    if($case==='late'){
        $lateDomain=static function($command)use(&$lateDomain,$wire,$clock){
            if($command==='getDomainInfo'){$clock->instant=$clock->now()->modify('+'.RenewalAdmission::READ_SECONDS.' seconds');}
            else{$wire->during=$lateDomain;}
        };$wire->during=$lateDomain;
    }
    $held=$completion->run($renewal->id);$clock->instant=$beforeRead;
    same('pending',$held->state,'On or unknown auto-renew never completes an acknowledged renewal');
    same('provider_auto_renew_unconfirmed',$held->safe_code,'Missing auto-renew-off proof uses existing staff reason: '.$case);
    same('provider_auto_renew_unconfirmed',$held->policy['renewal_execution']['reason'],'Unconfirmed auto-renew retains recovery guidance');
    same($originalAttempt,renewalRecord($held),'Readback hold preserves the original sealed attempt');
    same(null,$held->completed_at,'Readback hold records no completion');
    same(null,$held->cost['actual']??null,'Readback hold records no final charge');
    same($financial,nativeFinancialSnapshot($held),'Readback hold changes no holding or native finance');
    same(1,$writes,'Readback hold never retries purchase or changes provider settings');
}
Illuminate\Support\Facades\Auth::login(staffUser(['admin.domains.view']));
$guidance='Turn off auto-renew at NameSilo, then check status.';
$notice=RequestFacts::notice($held);
same([$guidance,'recheck'],[RequestFacts::for($held)['auto_renew']??null,$notice['action']??null],'Staff notice names removal and a fresh check');
expect(str_contains($notice['body']??'',$guidance),'Notice body carries the auto-renew instruction');
$limit=clone $held;$policy=$limit->policy;$policy['renewal_execution']['reason']='poll_limit_reached';$policy['renewal_polls']=5;$limit->policy=$policy;
$notice=RequestFacts::notice($limit);
expect(str_contains($notice['body']??'',$guidance)&&($notice['action']??null)==='resume_reads','Auto-renew instruction survives an exhausted read budget');
// The held sealed renewal's automatic correction is its own request, linked to the renewal, and turns auto-renew off once.
$autoRenew='Yes';$managementCalls=[];$money=static fn(array $snapshot)=>array_diff_key($snapshot,['domain'=>true,'operation_count'=>true]);
// The safe code alone triggers this correction, so an unreadable reply cannot stop it.
$replyRow=DB::table(OperationSchema::EVIDENCE)->where(['operation_id'=>$renewal->id,'kind'=>'reply'])->sole();
DB::table(OperationSchema::EVIDENCE)->where('id',$replyRow->id)->update(['evidence'=>'correction-unreadable-fixture']);
$correction=$produceCorrection();
same(['auto_renew_off',$renewal->operation_key],[$correction?->action,$correction?->policy['management']['correction']??null],'The held sealed renewal schedules its auto-renew correction');
same(['operation_key'=>$renewal->operation_key,'section'=>'auto_renew','generation'=>$renewal->authority_generation],$correction->policy['management']['correction_link'],'The correction links the sealed renewal');
Illuminate\Support\Facades\Cache::flush();// The renewal's own account write slot has passed.
same('succeeded',$managementEngine->run($correction->id,$managementDriver,Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background)->state,'The correction turns auto-renew off and reads it back');
same(1,count(array_keys($managementCalls,'removeAutoRenewal',true)),'The correction writes auto-renew off once');
same(null,$produceCorrection(),'The finished correction matches its renewal and is not requested again');
DB::table(OperationSchema::EVIDENCE)->where('id',$replyRow->id)->update(['evidence'=>$replyRow->evidence]);
same($originalAttempt,renewalRecord($renewal),'The correction preserves the sealed renewal attempt');
same([$money($financial),$financial['operation_count']+1],[$money(nativeFinancialSnapshot($renewal)),nativeFinancialSnapshot($renewal)['operation_count']],'The correction is its own request and changes no money');
same(1,$writes,'The correction never repeats the renewal');
$financial=nativeFinancialSnapshot($renewal);
$wire->auto='No';$wire->historyAction='unrecognized';
$unresolved=$completion->run($renewal->id);
same('pending',$unresolved->state,'Off readback alone cannot complete unresolved charge history');
same(null,$unresolved->safe_code,'Fresh off readback clears only the stale auto-renew reason');
same('original_attempt_unresolved',$unresolved->policy['renewal_execution']['reason'],'Unresolved history keeps its own recovery reason');
expect(!isset(RequestFacts::for($unresolved)['auto_renew'])&&(RequestFacts::notice($unresolved)['body']??null)!==$guidance,'Staff no longer told to remove auto-renew after fresh off proof');
same($financial,nativeFinancialSnapshot($unresolved),'Off with incomplete history preserves finance and holding');
same($originalAttempt,renewalRecord($unresolved),'Off with incomplete history preserves sealed attempt');
same(1,$writes,'Off alone triggers no extra write');
$wire->historyAction='renewal';
Illuminate\Support\Facades\Auth::login($user);
$wire->auto='No';$wire->omit=[];$wire->during=null;
echo "NameSilo renewal on/missing/invalid/outage/late holds preserve one write and native finance; staff guidance passed\n";
