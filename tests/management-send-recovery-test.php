<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native send bounds with local provider responses.
declare(strict_types=1);
require __DIR__.'/Support/management-send-bound.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement, ManagementEvidence, WriteFeatures};
// Old sealed attempts cannot claim a zero balance from an incomplete history.
[$d, $driver, $op] = $uncertain('bound-legacy');
$attempt = array_diff_key($op->fresh()->attempt, ['management_observation_attempts' => true]);
DB::table('domains_operations')->where('id', $op->id)->update(['attempt' => $op->fresh()->forceFill(['attempt' => $attempt])->getAttributes()['attempt']]);
$before = count($state->calls); $management->reconcile($op->id, $driver);
same($before, count($state->calls), 'Unknown legacy usage refuses before I/O');
commerceReject(fn () => $op->fresh()->update(['attempt' => [...$op->fresh()->attempt, 'management_observation_attempts' => 1]]));
// A current one-use staff review can read old sealed operations without replaying their mutation.
require __DIR__.'/Support/staff-panel.php';
$staff=staffUser(['admin.domains.view','admin.domains.operations.recheck']);
app()->instance(DomainManagement::class,$management);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\OwnerPorts::class,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts(static fn()=>$driver));
$staffRun=static function($op,?string $key=null)use($staff,$clock):string{
    $review=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::class;
    $key??=$review::ticket($staff->id,$op->domain_id,$op->id,'recheck',$review::stamp($op->fresh(),$op->domain->fresh()),$clock);
    (new \Paymenter\Extensions\Servers\Domains\Services\StaffOperations($clock))->run($staff,$op->domain_id,$op->id,'recheck',$key,'Review retained management result');
    return $key;
};
$original=$op->fresh()->attempt;$funding=$op->fresh()->funding;
$state->locked=false;
$key=$staffRun($op);
same('succeeded',$op->fresh()->state,'Reviewed legacy readback can resolve the original operation');
same(1,$op->fresh()->attempt['management_legacy_observation_attempts'],'Recovery reads have a separate durable budget');
same(false,isset($op->fresh()->attempt['management_observation_attempts']),'Unknown earlier usage is never reconstructed');
foreach($original as $field=>$value){
    if($field!=='management_reads'){same($value,$op->fresh()->attempt[$field],'Original attempt evidence retained');}
}
same($funding,$op->fresh()->funding,'Read recovery never changes funding');
same($before+2,count($state->calls),'Recovery performs only the ownership and lock reads');
$history=$op->fresh()->policy['staff_actions'];same(['read_requested','read_finished'],array_column($history,'phase'),'Recovery audit brackets provider I/O');
commerceReject(fn()=>$staffRun($op,$key));
same(true,ManagementEvidence::resolved($op->fresh(),$d->fresh()),'Recovered prior operation no longer blocks management');
same('queued',$submit($d,$driver,'lock',['locked'=>true])->state,'A new management request can follow the recovered operation');
// An unreviewed increment below the limit must reach the review guard.
[$unreviewedDomain,$unreviewedDriver,$unreviewed]=$uncertain('bound-unreviewed-legacy');
$unreviewedAttempt=array_diff_key($unreviewed->fresh()->attempt,['management_observation_attempts'=>true]);
DB::table('domains_operations')->where('id',$unreviewed->id)->update(['attempt'=>$unreviewed->fresh()->forceFill(['attempt'=>$unreviewedAttempt])->getAttributes()['attempt']]);
try{
    $unreviewed->fresh()->update(['attempt'=>[...$unreviewedAttempt,'management_legacy_observation_attempts'=>1]]);
    expect(false,'Unreviewed legacy increment must refuse below the cap');
}catch(DomainException $error){same('Reviewed legacy management recovery required.',$error->getMessage(),'Review gate rejects unreviewed increment');}
same($unreviewedAttempt,$unreviewed->fresh()->attempt,'Unreviewed increment retains the sealed evidence');
// Port resolution belongs to the same one-use audited read as provider observation.
[$portDomain,$portDriver,$portOp]=$uncertain('bound-port-failure');
$review=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::class;
$portKey=$review::ticket($staff->id,$portOp->domain_id,$portOp->id,'recheck',$review::stamp($portOp->fresh(),$portOp->domain->fresh()),$clock);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\OwnerPorts::class,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts(static function(){throw new DomainException('Offline owner port unavailable');}));
$beforePort=count($state->calls);
commerceReject(fn()=>$staffRun($portOp,$portKey));
$portAudit=$portOp->fresh()->policy['staff_actions']??[];
same(['read_requested','read_failed'],array_column($portAudit,'phase'),'Port resolution failure is audited in one read');
same($beforePort,count($state->calls),'Port resolution failure sends nothing');
commerceReject(fn()=>$staffRun($portOp,$portKey));
same($portAudit,$portOp->fresh()->policy['staff_actions'],'Failed port ticket cannot be replayed');
// Failed legacy reads spend slots durably; owner/background reconciliation cannot spend them.
[$d,$driver,$op]=$uncertain('bound-legacy-failed');
$attempt=array_diff_key($op->fresh()->attempt,['management_observation_attempts'=>true]);
DB::table('domains_operations')->where('id',$op->id)->update(['attempt'=>$op->fresh()->forceFill(['attempt'=>$attempt])->getAttributes()['attempt']]);
$driver=new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management(
    $driver->capabilities()->binding,static function($origin,$command,$parameters,$timeout)use($exchange):string{
        $reply=$exchange($origin,$command,$parameters,$timeout);
        if($command==='GetRegLock'){throw new RuntimeException('Offline recovery read failure');}
        return $reply;
    });
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\OwnerPorts::class,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts(static fn()=>$driver));
$before=count($state->calls);
$staff->role->update(['permissions'=>['admin.domains.view']]);
commerceReject(fn()=>$staffRun($op));same($before,count($state->calls),'Permission refusal makes no recovery reads');
$staff->role->update(['permissions'=>['admin.domains.view','admin.domains.operations.recheck']]);
$failedHistory=count($op->fresh()->attempt['management_reads']??[]);
for($i=1;$i<=20;$i++){
    $failed=false;
    try{$staffRun($op);}catch(\Paymenter\Extensions\Servers\Domains\Services\StaffReviewRefused $error){$failed=true;}
    $audit=$op->fresh()->policy['staff_actions'];
    same('read_failed',end($audit)['phase'],'Failed recovery read is not audited as completed');
    same(true,$failed,'Failed recovery read reaches the staff action error handler');
    same($i,$op->fresh()->attempt['management_legacy_observation_attempts'],'Failed legacy observation consumes recovery slot '.$i);
}
same($failedHistory,count($op->fresh()->attempt['management_reads']??[]),'Failed legacy reads add no completed history');
same($before+40,count($state->calls),'Twenty failed recovery observations are bounded');
$management->reconcile($op->id,$driver);commerceReject(fn()=>$staffRun($op));
same($before+40,count($state->calls),'Owner/background and exhausted staff entries cannot add sends');
commerceReject(fn()=>$op->fresh()->update(['attempt'=>[...$op->fresh()->attempt,'management_legacy_observation_attempts'=>1]]));
commerceReject(fn()=>$op->fresh()->update(['attempt'=>array_diff_key($op->fresh()->attempt,['management_legacy_observation_attempts'=>true])]));
// A frozen automatic correction survives a long outage after its fast-read budget is spent.
[$d,$driver,$original]=$holding(placeholderComFixtureName('bound-auto-recovery-placeholder.com'),\Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence::observed(['ns1.example.com','ns2.example.com'],'enom_getdns',$clock->now()));
$state->renew=true;$state->apply=false;
$driver=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($driver->capabilities()->binding,
    static function($origin,$command,$parameters,$timeout)use($exchange,$state):string{
        $reply=$exchange($origin,$command,$parameters,$timeout);
        if($command==='GetRenew'&&$state->fault==='GetRenew'){throw new RuntimeException('Offline Enom outage');}
        return $reply;
    });
$original->update(['policy'=>[...$original->policy,'reconciliation'=>[...($original->policy['reconciliation']??[]),'observed_corrections'=>['auto_renew']]]]);
$op=\Paymenter\Extensions\Servers\Domains\Services\CorrectionWork::produce($d,$management,$driver);
same('auto_renew_off',$op->action,'Automatic correction uses the frozen authorization');
$state->fault='GetRenew';$management->run($op->id,$driver);
for($i=1;$i<20;$i++){$management->reconcile($op->id,$driver);}
$sealed=$op->fresh()->attempt;$funding=$op->fresh()->funding;$payload=$op->fresh()->payload;
$clock->instant=$clock->now()->modify('+6 hours');$before=count($state->calls);
$management->reconcile($op->id,$driver);
same($before+2,count($state->calls),'Exhausted automatic correction still makes a slow status read during outage');
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\OwnerPorts::class,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts(static fn()=>$driver));
$before=count($state->calls);
try{$staffRun($op);expect(false,'Recent slow read must throttle Check status');}
catch(\Paymenter\Extensions\Servers\Domains\Services\StaffReviewRefused $e){expect(str_contains($e->getMessage(),'Try again'),'Throttled staff check gives the next step');}
same($before,count($state->calls),'Staff cannot hammer an exhausted request');
$clock->instant=$clock->now()->modify('+15 minutes');$before=count($state->calls);
try{$staffRun($op);expect(false,'Outage must not be reported as Status checked');}
catch(\Paymenter\Extensions\Servers\Domains\Services\StaffReviewRefused $e){expect(str_contains($e->getMessage(),'15 minutes'),'Failed staff read gives a plain retry step');}
same($before+2,count($state->calls),'Spaced Check status performs a real read after the fast budget');
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
$queued=\Illuminate\Support\Facades\Queue::pushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class)->count();
(new \Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch($clock))->enqueue($op->id,true);
same($clock->now()->getTimestamp()+21600,(int)DB::table('domains_runtime_operations')->where('operation_id',$op->id)->value('due_at'),'Normal scheduler waits until six hours after the staff read');
same($queued,\Illuminate\Support\Facades\Queue::pushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class)->count(),'Cooldown does not enqueue another read');
$clock->instant=$clock->now()->modify('+6 hours -1 second');$before=count($state->calls);
$management->reconcile($op->id,$driver);same($before,count($state->calls),'Automatic recovery waits six hours after any reserved read');
$clock->instant=$clock->now()->modify('+1 second');$state->fault='';$state->renew=false;
$other=new DomainManagement($bindings,$clock,new WriteFeatures(management:true),true);
$state->during=static function()use($other,$op,$driver,$state):void{
    $before=count($state->calls);$other->reconcile($op->id,$driver);same($before,count($state->calls),'Concurrent tick cannot reserve another slow read');
};
same('succeeded',$management->reconcile($op->id,$driver)->state,'Automatic correction resolves alone when registrar reads recover');
foreach($sealed as $field=>$value){if($field!=='management_read_at'){same($value,$op->fresh()->attempt[$field],'Recovery retains original sealed evidence');}}
same($funding,$op->fresh()->funding,'Read recovery retains all funding');same($payload,$op->fresh()->payload,'Read recovery retains frozen authorized details');
same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='SetRenew')),'Slow and staff recovery never resend the correction');
same(true,ManagementEvidence::resolved($op->fresh(),$d->fresh()),'Recovered correction releases management and renewal blocker');
same('queued',$submit($d,$driver,'lock',['locked'=>false])->state,'Management can continue after autonomous readback');
// A completed first history remains immutable while a spaced staff read resolves it.
[$d,$driver,$op]=$uncertain('bound-full-history-recovery');
for($i=1;$i<20;$i++){$management->reconcile($op->id,$driver);}
$history=$op->fresh()->attempt['management_reads'];$clock->instant=$clock->now()->modify('+15 minutes');$state->locked=false;
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\OwnerPorts::class,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts(static fn()=>$driver));
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$budget=new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class,new \Paymenter\Extensions\Servers\Domains\Services\Runtime);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,$budget);
$binding=$driver->capabilities()->binding;$origin=\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background;$lease=$budget->acquire($binding,$origin);$before=count($state->calls);
try{$staffRun($op);expect(false,'Busy account must not report Status checked');}
catch(\Paymenter\Extensions\Servers\Domains\Services\StaffReviewRefused $e){expect(str_contains($e->getMessage(),'account are busy')&&str_contains($e->getMessage(),'15 minutes'),'Busy account explains when to try again');}
same($before,count($state->calls),'Recovery respects the normal account read budget');$budget->release($binding,$origin,$lease);
$clock->instant=$clock->now()->modify('+15 minutes');$before=count($state->calls);$staffRun($op);
same($before+2,count($state->calls),'Spaced staff check reads beyond a full completed history');
same($history,$op->fresh()->attempt['management_reads'],'The first twenty completed reads stay immutable');
same('succeeded',$op->fresh()->state,'Staff read can settle the sealed request after the fast limit');
same(true,ManagementEvidence::resolved($op->fresh(),$d->fresh()),'Latest recovery read supplies completion proof');
commerceReject(fn()=>$op->fresh()->update(['attempt'=>[...$op->fresh()->attempt,'management_recovery'=>['number'=>0,'at'=>$clock->now()->format(DATE_ATOM),'reviewed'=>false]]]));
commerceReject(fn()=>$op->fresh()->update(['attempt'=>array_diff_key($op->fresh()->attempt,['management_recovery_read'=>true])]));
commerceReject(fn()=>$op->fresh()->update(['state'=>'uncertain','completed_at'=>null]));
$nativeSuccessful = true;
echo "Management send seal and paced read recovery: fast bounds, six-hour automatic checks, spaced staff checks and immutable history passed.\n";
