<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require_once __DIR__.'/Support/observation-work.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, DomainSnapshot, ExpiryPrecision, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, DomainObservation, HoldingObservationWork, ObservationProjection, ObservationSchema};

ObservationSchema::install();
$failures=[];
// Each negative case differs from expiry-only drift in one real stop or identity boundary.
foreach (['expiry','hard-stop','foreign-facts','other-drift','record-hold','foreign-work','leased'] as $case) {
    [$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-wake-'.$case.'-placeholder.com'));
    [$engine,$writer,$completion]=$compose($b);
    same('pending',$engine->run($op->id,$writer)->state,'Original renewal sent once');
    ObservationProjection::save($d,new DomainSnapshot($b,$d->domain,true,
        new DateEvidence('2028-10-18',ExpiryPrecision::Date,'synthetic'),$case==='other-drift'),TransferStatus::Unknown,$clock->now());
    $p=$d->observation;
    if($case==='foreign-facts'){$p['facts']['generation']++;}
    if($case==='record-hold'){$p['record_hold']=['identity'=>\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($d),'reason'=>'records_unavailable'];}
    $d->update(['observation'=>$p]);
    HoldingObservationWork::queueInitial($d,$clock->now());
    $q=HoldingObservationWork::current($d);
    $q->update(['safe_code'=>$case==='hard-stop'?'binding_mismatch':'budget_exhausted','tries'=>5,'resumes'=>0,
        'generation'=>$case==='foreign-work'?$d->authority_generation+1:$d->authority_generation,
        'claim'=>$case==='leased'?'existing-read':null,'lease_until'=>$case==='leased'?$clock->now()->getTimestamp()+300:0]);
    $before=DB::table('domains_observation_work')->where('domain_id',$d->id)->get()->toJson();
    $state->expiry='2028-10-18';$state->charge='8.00';
    same('succeeded',$completion->run($op->id)->state,'Renewal confirmed');
    try {
        if($case==='expiry'){
            same(false,$d->fresh()->observation['drift'],'Confirmed renewal recomputes expiry drift');
            $job=HoldingObservationWork::current($d)->sole();
            same(null,$job->safe_code,'Expiry-only budget stop wakes');
            same(4,(int)$job->tries,'Only one additional attempted read is available');
            same($clock->now()->getTimestamp(),(int)$job->due_at,'Read is due now');
            $once=json_encode($job);$completion->run($op->id);
            same($once,json_encode(HoldingObservationWork::current($d)->sole()),'Repeated completion cannot requeue again');
            // The real scheduler consumes the wake and returns to routine cadence after one successful read.
            $observation->name=$d->domain;$observation->expiryYears=2;
            DB::table('domains_observation_work')->where('domain_id','!=',$d->id)->update(['due_at'=>$clock->now()->getTimestamp()+86400]);
            $observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock));
            $work=observationFixtureScheduler($observer,$clock,$ports);$work->enroll($d->server_id);
            $calls=count($observation->calls);$work->tick(2);
            expect(count($observation->calls)>$calls,'Woken observation performs a registrar read');
            same('refreshed',HoldingObservationWork::current($d->fresh())->value('safe_code'),'One read restores normal observation');
            $calls=count($observation->calls);$work->tick(2);
            same($calls,count($observation->calls),'No immediate repeated read');
        }else{
            same($before,DB::table('domains_observation_work')->where('domain_id',$d->id)->get()->toJson(),'Unrelated stop, identity or lease stays unchanged: '.$case);
        }
        echo 'PASS '.$case."\n";
    }catch(Throwable $error){$failures[]=$case.': '.$error->getMessage();echo 'FAIL '.end($failures)."\n";}
    Cache::flush();
}
if($failures){throw new RuntimeException(implode("\n",$failures));}
$nativeSuccessful=true;
