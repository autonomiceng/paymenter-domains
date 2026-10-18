<?php
// Enom d1392a8 grace and covered renewal observation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{RenewalCode, RenewalRequest};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\RenewalRefused;
[$op,$d,$b]=$makeRenewal('renew-grace.com'); [$engine,$writer,$completion]=$compose($b);
$clock->instant=new DateTimeImmutable('2027-10-18T12:00:01Z');
same('pending',$engine->run($op->id,$writer)->state,'Accepted grace shape retains the original nested order');
same(['UpdateExpiredDomains',['DomainName'=>$d->domain,'NumYears'=>1,'UseCreditCard'=>'no']],$state->lastWrite,'Explicit one-year grace account-balance command');
same('9002',$op->fresh()->provider_order_id,'Grace original order retained without inventing completion');
$state->expiry='2028-10-18'; $state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Grace completes only with unique actual charge and matching full-term expiry');
[$op,$d,$b]=$makeRenewal('renew-transient.com'); [$engine,$writer,$completion]=$compose($b);
same('pending',$engine->run($op->id,$writer)->state,'Original transient cycle sent');
$state->fail=ReadCommand::Info;
for($i=0;$i<6;$i++){ $completion->run($op->id); }
same(0,$op->fresh()->policy['renewal_polls']??0,'Six failed reads do not exhaust covered observations');
$state->fail=null; $state->expiry='2028-10-18'; $state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Valid original evidence completes after transient outage');
[$op,$d,$b]=$makeRenewal('renew-lower-actual.com'); [$engine,$writer,$completion]=$compose($b);
$engine->run($op->id,$writer); $state->expiry='2028-10-18'; $state->charge='7.99';
same('succeeded',$completion->run($op->id)->state,'Incurred lower actual charge retained');
try { $authority->capture(new RenewalRequest($d->id,1,2,'2028-10-18','456')); throw new RuntimeException('Changed actual cost incorrectly reused'); }
catch(RenewalRefused $e){ same(RenewalCode::Cost,$e->reason,'Lower actual conservatively refuses future cost authority'); }
$clock->instant=new DateTimeImmutable('2028-09-27T12:00:00Z');
$count=App\Models\Invoice::count(); commerceReject(fn()=>$billing->invoice($d->id,$user)); same($count,App\Models\Invoice::count(),'Changed actual cost refuses new debt before native writes');
[$op,$d,$b]=$makeRenewal('renew-late-ack.com'); [$engine,$writer]=$compose($b); $writes=$state->writes;
$state->afterWrite=static function()use($clock,$engine,$op,$writer){ $clock->instant=$clock->now()->modify('+361 seconds'); same('uncertain',$engine->run($op->id,$writer)->state,'Expired running claim quarantined without replay'); };
same('uncertain',$engine->run($op->id,$writer)->state,'Late acknowledgement cannot restore a quarantined claim');
same($writes+1,$state->writes,'Stale and late invocations share one write');
[$op,$d,$b]=$makeRenewal('renew-concurrent.com'); [$engine,$writer]=$compose($b); $writes=$state->writes;
$state->during=static function()use($engine,$writer,$op){
    $before=$op->fresh()->getRawOriginal(); $engine->run($op->id,$writer); same($before,$op->fresh()->getRawOriginal(),'Concurrent run cannot change owned preflight claim');
};
same('pending',$engine->run($op->id,$writer)->state,'Original preflight survives concurrent invocation'); same($writes+1,$state->writes,'Concurrent workers share one send');
[$op,$d,$b,$bill]=$makeRenewal('renew-prewire.com'); [$engine,$writer]=$compose($b); $writes=$state->writes;
Paymenter\Extensions\Servers\Domains\Models\Operation::updated(static function($row)use($op,$bill){
    if($row->id===$op->id && $row->state==='running'){ Illuminate\Support\Facades\DB::table('invoices')->where('id',$bill->id)->update(['status'=>'cancelled']); }
});
same('held',$engine->run($op->id,$writer)->state,'Changed sealed authority retains positive prewire refusal');
same($writes,$state->writes,'Known prewire refusal never enters exchange'); same('definitely_unsent',$op->fresh()->attempt['execution']['send_state'],'Typed definitely-unsent evidence retained');
$engine->run($op->id,$writer); same($writes,$state->writes,'Sealed prewire refusal cannot replay');
[$op,$d,$b]=$makeRenewal('renew-postsend-cancel.com'); [$engine,$writer,$completion]=$compose($b); $engine->run($op->id,$writer);
App\Models\ServiceCancellation::create(['service_id'=>$d->service_id,'type'=>'end_of_period','reason'=>'Synthetic']);
$state->expiry='2028-10-18'; $state->charge='8.00'; same('pending',$completion->run($op->id)->state,'Post-send cancellation retains unresolved original attempt');
same('2027-10-18',$d->fresh()->provider_expires_at->format('Y-m-d'),'Cancellation cannot advance local expiry or reactivate native service');
[$op,$d,$b]=$makeRenewal('renew-below-cost.com',true,1,false,static function($d)use($clock){
    $price=Paymenter\Extensions\Servers\Domains\Models\Price::where(['server_id'=>$d->server_id,'action'=>'renew'])->sole();
    $price->update(['retail'=>'1.00','revision'=>$price->revision+1,'observed_at'=>$clock->now(),'fresh_until'=>$clock->now()->modify('+1 day')]);
});
same('1.00',$op->amount,'Below-cost renewal billed at exact retail with no floor');
[$engine,$writer,$completion]=$compose($b); same('pending',$engine->run($op->id,$writer)->state,'Below-cost paid renewal still sends');
$state->expiry='2028-10-18'; $state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Below-cost outcome completes');
same('below_cost',$op->fresh()->policy['renewal_execution']['reason'],'Below-cost outcome alerts staff');
echo "Grace acknowledgement, outage recovery, actual-cost retention and late claim safety passed\n"; $nativeSuccessful=true;
