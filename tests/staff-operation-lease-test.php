<?php
// Real claim/staff/seal/send interleavings; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions;
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{OperationClaims,StaffOperationReview,WriteAcceptance};

$message='A worker is processing this request. Wait for its lease to expire, then reload this page.';
$refused=static function(Closure $action)use($message):void {
    try{$action();}catch(DomainException $e){same($message,$e->getMessage(),'Clear live-worker refusal');return;}
    throw new RuntimeException('Expected live-worker staff refusal.');
};
// Exercise both windows with every action offered for running operations. Keep checking worker
// progress even on old code, so the red output proves the held/possibly-sent symptoms themselves.
$missed=0;
foreach(['before_seal','before_send'] as $phase){
    foreach(['recheck','resume_reads','resume_observation'] as $action){
        $op=$new('staff-lease-'.str_replace('_','-',$phase).'-'.str_replace('_','-',$action).'.com');
        [$claimed,$domain,$binding]=$claims->claim($op->id);
        DB::table('domains_observation_work')->insert(['server_id'=>$op->server_id,'account_fingerprint'=>$op->account_fingerprint,
            'domain_id'=>$op->domain_id,'generation'=>$op->authority_generation,'due_at'=>0,'tries'=>5,'safe_code'=>'read_unavailable']);
        $context=new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+300 seconds'),new RequestBudget(10),$clock);
        $ready=$preflight->inspect($claimed,$domain,$context);
        $sealed=$phase==='before_send'?$claims->seal($claimed,$ready,$clock->now()):null;
        $before=$op->fresh()->getRawOriginal();$reads=count($observation->calls);$caught=false;
        try{$run($op->fresh(),$action);}catch(DomainException $e){$caught=$e->getMessage()===$message;}
        if(!$caught){$missed++;}
        $workerError=null;
        try{
            $sealed??=$claims->seal($claimed,$ready,$clock->now());
            if($sealed){
                $admitted=new AdmittedOperation($binding,$ready->query,$sealed->operation_key,$sealed->attempt['claim_key'],
                    $sealed->authority_generation,'operation:'.$sealed->id.':seal',OperationClaims::payloadDigest($sealed),$ready->cost);
                $claims->verifySend($sealed,$admitted,$context,$ready,null,new WriteAcceptance($binding,true,true,25,static fn()=>true));
            }
        }catch(DomainException $e){$workerError=$e->getMessage();}
        echo $phase.' '.$action.': refused='.($caught?'yes':'no').' state='.$op->fresh()->state.' send='.$op->fresh()->attempt['send_state'].' worker_error='.($workerError??'none')."\n";
        if($caught){
            // Ignore the worker's own subsequent seal; staff alone made no changes or provider reads.
            same($before['policy'],$op->fresh()->getRawOriginal('policy'),'Staff preserves frozen policy bytes');
            same($reads,count($observation->calls),'Refused staff action performs no provider reads');
            expect($sealed!==null&&$workerError===null,'Worker seals and verifies send after staff refusal');
            Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden($action);
        }
    }
}
same(0,$missed,'Every live-worker staff action is refused before writing');
// A modal opened before claim must show the safe refusal when submitted after claim.
$op=$new('staff-lease-open-modal.com');
$oldKey=$ticket($op,'recheck');
$action=collect(OperationActions::make($op,StaffOperationReview::stamp($op,$op->domain)))->first(fn($a)=>$a->getName()==='recheck');
[$claimed]=$claims->claim($op->id);
\Illuminate\Support\Facades\Auth::setUser($staff);
$refused(fn()=>$run($op->fresh(),'recheck'));
($action->getActionFunction())(['review_key'=>$oldKey,'reason'=>'Review current worker']);
Notification::assertNotified(Notification::make()->danger()->title('Review refused')->body($message));
// Every action is hidden during a live lease, including financial actions and observation resume.
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){same(false,OperationActions::relevant($action,$op->fresh()),'Live lease hides '.$action);}
// Equal-to-now and absent leases have the same expired semantics as OperationClaims.
foreach(['equal','past','missing'] as $expiry){
    [$stale]=$prepare('staff-lease-expired-'.$expiry.'.com','absent');
    $lease=$stale->lease_expires_at;
    if($expiry==='missing'){$stale->update(['lease_expires_at'=>null]);}else{$clock->instant=$lease->modify($expiry==='past'?'+1 second':'+0 seconds');}
    \Illuminate\Support\Facades\Auth::setUser($staff);
    expect(OperationActions::relevant('recheck',$stale->fresh()),'Expired running recheck stays visible');
    $run($stale->fresh(),'recheck');
    same('uncertain',$stale->fresh()->state,'Expired running request enters reconciliation');
    same(2,count($stale->fresh()->policy['staff_actions']),'Expired recheck retains both audit phases');
    $run($stale->fresh(),'resume_reads');
}
// Even a direct caller starting a worker during read I/O cannot mutate its frozen evidence
// when the read-finished audit returns; the durable requested phase remains retained.
$op=$new('staff-lease-finish-race.com');
$review=new StaffOperationReview($staff->id,$op->domain_id,$op->id,'recheck',$ticket($op,'recheck'),'Read original attempt',$clock);
$refused(fn()=>$review->read(function()use($claims,$op){expect($claims->claim($op->id)!==null,'Worker claims during unlocked read');}));
same(1,count($op->fresh()->policy['staff_actions']),'Requested audit survives without changing the live claim');
echo "Live staff lease and expired recovery boundaries passed.\n";
$nativeSuccessful=true;
