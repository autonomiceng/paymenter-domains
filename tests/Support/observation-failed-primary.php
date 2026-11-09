<?php
// Enom 7096bae failed-refresh retention; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{ManagementPayload,ObservationRecords,ObservationWork,OwnerRecords};
// Ordinary, current, complete nonempty inventory: no history overflow or existing hold.
foreach (['nameservers'=>['nameservers'=>$state->ns], 'glue_add'=>['host'=>'ns1.discovery-placeholder.com','ip'=>'8.8.8.8']] as $action=>$payload) {
    Cache::flush();$op=$submit($d,$driver,$action,$payload);
    same('succeeded',$management->run($op->id,$driver)->state,'Seed confirmed '.$action);
}
$clock->instant=$clock->now()->modify('+61 seconds');
same('refreshed',$observer->run($d->id,readRecords:true),'Seed complete inventory and successful fact timestamps');
require_once __DIR__.'/observation-work.php';
$failedWork=observationFixtureScheduler($observer,$clock,activeFixturePorts($bindings));$failedWork->enroll($d->server_id);
foreach (['malformed','transport'] as $failure) {
    $saved=$d->fresh();$prior=$saved->observation;
    same(ObservationRecords::identity($saved),$prior['records']['identity'],'Current inventory before '.$failure);
    expect(!ObservationRecords::held($saved),'No pre-existing hold before '.$failure);
    expect($saved->operations()->count()<100,'Ordinary history below overflow threshold');
    foreach (['dns','srv','ds'] as $kind) {
        same('complete',$prior['records']['families'][$kind]['state'],'Complete saved '.$kind);
        expect(count($prior['records']['families'][$kind]['records'])>0,'Nonempty saved '.$kind);
    }
    same(['ns1.discovery-placeholder.com'=>'8.8.8.8'],$prior['records']['families']['glue']['records'],'Known saved child host');
    same($state->ns,$prior['sections']['nameservers']['facts']['nameservers'],'Saved nameservers');
    $synced=$saved->last_synced_at->format(DATE_ATOM);$operations=$saved->operations->map->getAttributes()->all();
    $stale=[];foreach (['dns','srv'] as $kind) {
        $rows=$prior['records']['families'][$kind]['records'];
        $stale[$kind]=$post($kind,['records'=>$rows,'preimage_digest'=>ManagementPayload::digest($rows,$kind)]);
    }
    $clock->instant=$clock->now()->modify('+61 seconds');$state->calls=[];$observation->calls=[];
    if ($failure==='malformed') { $observation->fault='info_missing'; }
    else { $observation->during=static function():void { throw new RuntimeException('Synthetic primary transport failure'); }; }
    $failureCode=$failure==='malformed'?'read_no_evidence':'read_failed';
    same($failureCode,$failedWork->tick(2)[$d->server_id]['holding'],'All-failed '.$failure.' observation reports failure');
    $firstFailureAt=$clock->now()->format(DATE_ATOM);
    if($failure==='transport'){
        // Transient failures retry automatically, then stop at the existing five-attempt limit.
        for($retry=1;$retry<5;$retry++){
            expect(!ObservationRecords::stopped($d->fresh()),'A transient failure before the ceiling remains automatic');
            $clock->instant=$clock->now()->modify('+3601 seconds');
            $observation->during=static function():void {throw new RuntimeException('Synthetic primary transport failure');};
            same($failureCode,$failedWork->tick(2)[$d->server_id]['holding'],'Bounded transient retry retains its cause');
        }
    }
    $expectedCalls=array_fill(0,$failure==='transport'?5:1,ReadCommand::Info);
    $fresh=$d->fresh();$current=$fresh->observation;
    same($prior['records'],$current['records'],'All-failed '.$failure.' preserves exact inventory/time/watermark');
    same($prior['facts'],$current['facts'],'All-failed '.$failure.' preserves successful facts and timestamp');
    same($synced,$fresh->last_synced_at->format(DATE_ATOM),'All-failed read cannot advance last sync');
    same($prior['sections'],$current['sections'],'All-failed read preserves saved nameservers/section timestamps');
    same(array_diff_key($prior,['read_retry'=>true]),array_diff_key($current,['record_hold'=>true,'record_failure'=>true,'read_retry'=>true]),'Only separate failure and retry markers change observation');
    if($failure==='transport'){same(5,\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::current($fresh)->value('tries'),'Transient recovery stops after five actual failed reads');
        same((int)\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::current($fresh)->value('id'),$current['read_retry']['work_id'],'Retry audit names its actual work');}
    same(['identity'=>ObservationRecords::identity($fresh),'at'=>$clock->now()->format(DATE_ATOM),'code'=>$failureCode],$current['record_failure'],'Current primary failure retained separately');
    same([...$current['record_failure'],'at'=>$firstFailureAt,'reason'=>$failureCode],$current['record_hold'],'The original failure holds the record stage through bounded retries');
    same($expectedCalls,$observation->calls,'First primary failure stops all later domain reads');
    same([],$state->calls,'No record reads or provider writes after primary failure');
    same($operations,$fresh->operations->map->getAttributes()->all(),'All operation and financial evidence retained');
    $failedJob=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();
    same($failureCode,$failedJob->safe_code,'Scheduled work stops on failed primary read');
    expect(ObservationRecords::stopped($fresh),'Current failed checks require explicit resume');
    $dom=storefrontDom($page());
    foreach ($stale as $kind=>$request) {
        same(0,$dom->query('//form[contains(@action,"/manage/'.$kind.'")]')->length,'Retained '.$kind.' cannot enable stale whole-list form');
        $count=Operation::count();$controller->manage($request,$d->id,$kind);
        same($count,Operation::count(),'Previously rendered '.$kind.' POST is blocked after failed refresh');
    }
    same([],$state->calls,'Stale form attempts never write provider records');
    $clock->instant=$clock->now()->modify('+1 day');
    same('idle',$failedWork->tick(2)[$d->server_id]['holding'],'Stopped work never retries automatically');
    same($expectedCalls,$observation->calls,'No later successful primary calls');
    $observation->fault='';$failedWork->resume($failedJob->id,'staff_primary_resume');
    expect(!ObservationRecords::held($d->fresh()),'Resume releases hold for one new record attempt');
    same($current['record_failure'],$d->fresh()->observation['record_failure'],'Resume retains failure until successful record check');
    foreach (['dns','srv'] as $kind) {
        expect(!isset(OwnerRecords::forDomain($d->fresh())[$kind]),'Resume alone cannot authorize stale '.$kind.' edits');
        $rows=$prior['records']['families'][$kind]['records'];$count=Operation::count();
        $controller->manage($post($kind,['records'=>$rows,'preimage_digest'=>ManagementPayload::digest($rows,$kind)]),$d->id,$kind);
        same($count,Operation::count(),'Stale '.$kind.' POST remains blocked before resumed check');
    }
    same('refreshed',$failedWork->tick(2)[$d->server_id]['holding'],'Resumed daily primary read succeeds');
    expect(!isset($d->fresh()->observation['record_failure']),'A resumed scheduled read confirms records and clears the failure');
    foreach(['dns','srv','ds']as$kind){same('complete',$d->fresh()->observation['records']['families'][$kind]['state'],'Resumed scheduled read confirms '.$kind);}
    same($clock->now()->format(DATE_ATOM),$d->fresh()->observation['records']['at'],'Resumed record evidence has the actual read time');
    same('refreshed',$observer->run($d->id,readRecords:true),'Sync now confirms current inventory again');
    expect(!isset($d->fresh()->observation['record_failure']),'Successful inventory replaces failure marker');
    same(1,storefrontDom($page())->query('//form[contains(@action,"/manage/dns")]')->length,'Successful resumed observation restores edit form');
    // Make the next failure due without reusing a hold or an exhausted job.
    $clock->instant=$clock->now()->modify('+1 day');
}
// Leave the later independent child-host discovery scenarios an empty local history.
Cache::flush();$op=$submit($d,$driver,'glue_delete',['host'=>'ns1.discovery-placeholder.com']);
same('succeeded',$management->run($op->id,$driver)->state,'Remove seeded child host after retention cases');
// A registration in progress may not be in the account yet: a not-found read rechecks a bounded number of times, then stops.
$pendingJob=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();$pendingOperations=$d->fresh()->operations->map->getAttributes()->all();
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'registration_pending']);
DB::table('domains_observation_work')->where('id',$pendingJob->id)->update(['safe_code'=>null,'tries'=>0,'claim'=>null,'lease_until'=>0,'due_at'=>$clock->now()->getTimestamp()]);
$observation->calls=[];$state->calls=[];$observation->fault='absent';
for($try=1;$try<\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::LIMIT;$try++){
    same('read_failed',$failedWork->tick(2)[$d->server_id]['holding'],'Pending not-found read '.$try.' keeps an automatic recheck');
    $clock->instant=$clock->now()->modify('+3601 seconds');
}
same('domain_not_in_account',$failedWork->tick(2)[$d->server_id]['holding'],'The last recheck stops with the exact cause');
$clock->instant=$clock->now()->modify('+1 day');same('idle',$failedWork->tick(2)[$d->server_id]['holding'],'No recheck after the limit');
same(array_fill(0,\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::LIMIT,ReadCommand::Info),$observation->calls,'Each recheck is one domain read');
same([],$state->calls,'Rechecks send no registrar writes');
same($pendingOperations,$d->fresh()->operations->map->getAttributes()->all(),'Rechecks change no operation or payment evidence');
$observation->fault='';DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'active']);
DB::table('domains_observation_work')->where('id',$pendingJob->id)->update(array_diff_key((array)$pendingJob,['id'=>true]));
// Reset this fixture's turn before the independent two-account fairness scenario below.
DB::table('domains_observation_queues')->where('server_id',$d->server_id)->update(['turn'=>0]);
