<?php
// Enom 444f31c management-actions/request-gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementEvidence,WriteFeatures};
[$d,$driver]=$holding(placeholderComFixtureName('manage-empty-email-placeholder.com'));$state->fault='empty_ack';$op=$submit($d,$driver,'auth_code_email');same('uncertain',$management->run($op->id,$driver)->state,'Empty success envelope never acknowledges email');same(false,isset($op->fresh()->attempt['management_ack']),'No fabricated email acknowledgement');
for($i=1;$i<20;$i++){$management->reconcile($op->id,$driver);}same(20,count($op->fresh()->attempt['management_reads']),'Readback history bounded');$calls=count($state->calls);same($op->fresh()->getAttributes(),$management->reconcile($op->id,$driver)->getAttributes(),'Exhausted history leaves the operation unchanged');same($calls,count($state->calls),'Exhausted history refuses before provider reads');
[$d,$driver]=$holding(placeholderComFixtureName('manage-retire-local-placeholder.com'));$op=$submit($d,$driver,'lock',['locked'=>false]);same(true,ManagementEvidence::resolved($op,$d),'Queued unsent request never blocks renewal');same('rejected',$management->retire($user,$op->id)->state,'Unsent local request retired');same([],$state->calls,'Local retirement no I/O');
[$d,$driver]=$holding(placeholderComFixtureName('manage-definite-refusal-placeholder.com'));$state->fault='refused';$op=$submit($d,$driver,'lock',['locked'=>false]);same('rejected',$management->run($op->id,$driver)->state,'Documented nonfinancial provider refusal final');same(true,ManagementEvidence::resolved($op->fresh(),$d),'Definite nonfinancial refusal releases renewal blocker');same('sealed',$op->fresh()->attempt['seal'],'Refusal never relabels send as unsent');
[$d,$driver]=$holding(placeholderComFixtureName('manage-delayed-email-placeholder.com'));$one=$submit($d,$driver,'auth_code_email');$clock->instant=$clock->now()->modify('+11 minutes');same($one->id,$submit($d,$driver,'auth_code_email')->id,'Delayed duplicate email still merges the unfinished request');
// Preserve the send-time cooldown proof for duplicate rows saved by an older deployment.
$two=$one->replicate();$two->operation_key='manage:'.$d->id.':'.$key();$two->save();same('succeeded',$management->run($one->id,$driver)->state,'Delayed first email accepted');Cache::flush();same('held',$management->run($two->id,$driver)->state,'Send-time cooldown blocks queued second email');same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='SynchAuthInfo')),'Queued emails cannot burst');
[$d,$driver]=$holding('example.com');$op=$submit($d,$driver,'lock',['locked'=>false]);
foreach ([['running','sealed',0,360],['running','absent',0,-1],['uncertain','sealed',20,-1],['pending','sealed',0,-1],['succeeded','sealed',0,-1]] as [$pollState,$seal,$reads,$lease]) {
    $attempt=['seal'=>$seal,'management_reads'=>array_fill(0,$reads,['confirmed'=>false])];
    // Exhaustion is ineligible only until the slow read interval has elapsed.
    if($reads===20){$attempt['management_read_at']=$clock->now()->format(DATE_ATOM);}
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$pollState,'attempt'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($attempt)), 'lease_expires_at'=>$clock->now()->modify(($lease>=0?'+':'').$lease.' seconds')]);
    $before=$op->fresh()->getAttributes();$calls=count($state->calls);
    for($i=0;$i<2;$i++){same($before,$management->reconcile($op->id,$driver)->getAttributes(),'Ineligible poll preserves every operation field');}
    same($calls,count($state->calls),'Ineligible polls make no provider calls');
}
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['authority_generation'=>$d->authority_generation+1]);
try{$management->reconcile($op->id,$driver);expect(false,'Changed authority must still fail');}
catch(DomainException $e){same('Management binding changed.',$e->getMessage(),'Real binding failure remains visible');}
$nativeSuccessful=true;echo "Native management scenario passed\n";
