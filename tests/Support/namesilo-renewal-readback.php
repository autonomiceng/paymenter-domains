<?php
// Fresh auto-renew-off proof after one sealed write; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
use Paymenter\Extensions\Servers\Domains\Services\RenewalAdmission;
require_once __DIR__.'/staff-panel.php';
$financial=nativeFinancialSnapshot($renewal);$originalAttempt=$renewal->fresh()->attempt;
foreach(['on','missing','invalid','outage','late'] as $case){
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
    same($originalAttempt,$held->attempt,'Readback hold preserves the original sealed attempt');
    same(null,$held->completed_at,'Readback hold records no completion');
    same(null,$held->cost['actual']??null,'Readback hold records no final charge');
    same($financial,nativeFinancialSnapshot($held),'Readback hold changes no holding or native finance');
    same(1,$writes,'Readback hold never retries purchase or changes provider settings');
}
Illuminate\Support\Facades\Auth::login(staffUser(['admin.domains.view']));
$guidance='Check auto-renew for this domain at NameSilo and turn it off if it is on. Then choose Re-check now, or Resume reconciliation reads if the read limit is reached. Completion requires a fresh check confirming auto-renew is off.';
expect(in_array($guidance,RequestFacts::review($held),true),'Staff review renders the removal and fresh-check instruction');
$limit=clone $held;$policy=$limit->policy;$policy['renewal_execution']['reason']='poll_limit_reached';$policy['renewal_polls']=5;$limit->policy=$policy;
expect(in_array('Read limit reached. '.$guidance,RequestFacts::review($limit),true),'Auto-renew instruction survives an exhausted read budget');
$wire->auto='No';$wire->historyAction='unrecognized';
$unresolved=$completion->run($renewal->id);
same('pending',$unresolved->state,'Off readback alone cannot complete unresolved charge history');
same(null,$unresolved->safe_code,'Fresh off readback clears only the stale auto-renew reason');
same('original_attempt_unresolved',$unresolved->policy['renewal_execution']['reason'],'Unresolved history keeps its own recovery reason');
expect(!in_array($guidance,RequestFacts::review($unresolved),true),'Staff no longer told to remove auto-renew after fresh off proof');
same($financial,nativeFinancialSnapshot($unresolved),'Off with incomplete history preserves finance and holding');
same($originalAttempt,$unresolved->attempt,'Off with incomplete history preserves sealed attempt');
same(1,$writes,'Off alone triggers no extra write');
$wire->historyAction='renewal';
Illuminate\Support\Facades\Auth::login($user);
$wire->auto='No';$wire->omit=[];$wire->during=null;
echo "NameSilo renewal on/missing/invalid/outage/late holds preserve one write and native finance; staff guidance passed\n";
