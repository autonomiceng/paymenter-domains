<?php
// A lost Enom renewal reply keeps its safe reason for staff and the log. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
use Paymenter\Extensions\Servers\Domains\Data\SendFailure;
require __DIR__.'/Support/staff-panel.php';staffPanel();
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.secrets-metadata']);
$logged=[];app('events')->listen(MessageLogged::class,static function(MessageLogged $m)use(&$logged):void{ if($m->message==='Domains renewal reply not recorded.'){$logged[]=[$m->level,$m->context];} });
// Enom renewed, then its reply was lost on the wire: the attempt keeps why, and never sends a second Extend.
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-send-failure-placeholder.com'));[$engine,$writer]=$compose($b);
$state->lost=new SendFailure('timeout',24993);$writes=$state->writes;
$result=$engine->run($op->id,$writer)->fresh();
same('uncertain',$result->state,'A lost reply stays uncertain');same('renewal_confirmation_required',$result->safe_code,'Staff confirm the renewal');
same(null,$result->attempt['execution'],'Lost reply keeps recorded-null execution');
same(['category'=>'timeout','timeout_ms'=>24993],$result->attempt['send_failure']??null,'The attempt keeps the fixed reason and call limit');
same([['warning',['operation_id'=>$op->id,'exception_class'=>SendFailure::class,'category'=>'timeout','timeout_ms'=>24993]]],$logged,'The lost reply is logged with safe facts only');
same($writes+1,$state->writes,'One Extend only');$engine->run($op->id,$writer);same($writes+1,$state->writes,'No replay');
Auth::setUser($staff);
same('timeout; time limit 24993 ms',RequestFacts::for($result)['send_failure']??null,'Staff sees the safe reason');
Livewire\Livewire::test(ViewOperation::class,['record'=>$result->id])->assertSee('Registrar reply')->assertSee('timeout; time limit 24993 ms');
// A failure with no known reason is still logged, and none is made up.
[$other,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-send-other-placeholder.com'));[$engine,$writer]=$compose($b);
$state->lost=true;$logged=[];
$result=$engine->run($other->id,$writer)->fresh();
same('uncertain',$result->state,'An unexplained lost reply stays uncertain');expect(!array_key_exists('send_failure',$result->attempt),'No reason is made up');
same([['warning',['operation_id'=>$other->id,'exception_class'=>RuntimeException::class]]],$logged,'An unexplained lost reply is still logged');
$nativeSuccessful=true;echo "Lost renewal reply logged and shown to staff without replay.\n";
