<?php
// Required renewal subread recovery; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/lifecycle-facts.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
foreach(['assessment','flags','renewal','extend','quote','expired','balance'] as $phase){
    [$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-read-'.$phase.'-placeholder.com')); [$engine,$writer]=$compose($b); $writes=$state->writes;
    if($phase==='assessment'){ $state->fail=ReadCommand::Info; }
    elseif($phase==='flags'){ $state->duringCommand=ReadCommand::Check; $state->during=static fn()=>$state->fail=ReadCommand::Info; }
    else{ $state->fail=match($phase){ 'renewal'=>ReadCommand::Renewal,'extend'=>ReadCommand::RenewalInfo,'quote'=>ReadCommand::Check,'expired'=>ReadCommand::Expired,'balance'=>ReadCommand::Balance }; }
    if($phase==='expired'){ $clock->instant=new DateTimeImmutable('2027-10-18T12:00:01Z');storeLifecycleFixture($b,lifecycleFixture($clock->now())); }
    same('queued',$engine->run($op->id,$writer)->state,'Normalized '.$phase.' transport outage stays retryable');
    same($phase==='balance'?'registrar_balance_insufficient_or_unknown':'renewal_reads_unavailable',$op->fresh()->safe_code,'Typed transient read reason retained'); same($writes,$state->writes,'Outage sends nothing');
    $state->fail=null; $state->duringCommand=null; Cache::flush();
    same('pending',$engine->run($op->id,$writer)->state,'Recovered '.$phase.' reads allow the original paid obligation');
    same($writes+1,$state->writes,'Recovery sends once');
}
$admissionReads = [ReadCommand::Info, ReadCommand::RenewalInfo, ReadCommand::Check, ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Balance];
foreach ([ReadCommand::Lock, ReadCommand::Privacy] as $unused) {
    [$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-unused-'.strtolower($unused->name).'-placeholder.com')); [$engine,$writer,$completion]=$compose($b); $writes=$state->writes;
    $state->fail=$unused;
    same('pending',$engine->run($op->id,$writer)->state,'Unused lock/privacy failure cannot block renewal admission');
    same([...$admissionReads,...$admissionReads],$state->calls,'Both admissions omit unused flag reads even when unavailable');
    $state->calls=[];$state->expiry='2028-10-18';$state->charge='8.00';
    same('succeeded',$completion->run($op->id)->state,'Unused lock/privacy failure cannot block renewal confirmation');
    same([ReadCommand::Info,ReadCommand::Renewal,ReadCommand::Info,ReadCommand::Expiry,ReadCommand::Order],$state->calls,'Confirmation omits unused flags and preserves dedicated expiry/order');
    same($writes+1,$state->writes,'Unused reads create no retry or extra write');$state->fail=null;
}
foreach ([1,2] as $pass) {
    [$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-pause-'.$pass.'-placeholder.com')); [$engine,$writer]=$compose($b); $writes=$state->writes;
    $hook=static function () use (&$hook,$state,$pass,$renewBindings,$b) {
        if (count(array_filter($state->calls,static fn($c)=>$c===ReadCommand::Balance))===$pass) { $renewBindings->setProcessing($b->serverId,false); }
        else { $state->during=$hook; }
    };
    $state->duringCommand=ReadCommand::Balance; $state->during=$hook;
    same('queued',$engine->run($op->id,$writer)->state,'Processing pause during admission pass '.$pass.' stays retryable');
    same('renewal_acceptance_required',$op->fresh()->safe_code,'Configuration reason survives authority recheck'); same($writes,$state->writes,'Paused processing sends nothing');
    same([],$op->fresh()->attempt??[],'Pause precedes sealing');
    $renewBindings->setProcessing($b->serverId, true, syntheticActivation()); $state->during=null; $state->duringCommand=null; Cache::flush();
    same('pending',$engine->run($op->id,$writer)->state,'Resumed processing recovers the same obligation'); same($writes+1,$state->writes,'Resumption sends exactly once');
}
echo "Every required Enom renewal subread recovers without holding or replay\n"; $nativeSuccessful=true;
