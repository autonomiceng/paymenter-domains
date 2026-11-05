<?php
// Native engine attempts and the real scheduler admission are required; wire calls are synthetic.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require_once __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Services\{ManagementEvidence,ManagementProgress,RuntimeDispatch,RuntimeSchema};
RuntimeSchema::install();[$d,$driver]=$holding(placeholderComFixtureName('runtime-recovery-placeholder.com'));
$op=$submit($d,$driver,'lock',['locked'=>false]);
$state->fault='GetRegLock';$management->run($op->id,$driver);$op->refresh();
same('held',$op->state,'Failed preparation leaves a held request');
same('management_unconfirmed',$op->safe_code,'The walk preparation failure');
same(true,ManagementEvidence::idle($op),'No write grant means Not sent is true');
expect(ManagementProgress::for($d)['lock']['pending'],'Customer is promised an automatic retry');
$before=count($state->calls);$clock->instant=$clock->now()->modify('+15 minutes');$state->fault='';
(new RuntimeDispatch($clock))->enqueue($op->id);
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,fn($job)=>$job->operationId===$op->id);
$management->run($op->id,$driver);$op->refresh();
same('succeeded',$op->state,'The retained failed preparation settles after the next scheduled retry');
same(1,count(array_filter($state->calls,fn($call)=>$call[0]==='SetRegLock')),'Exactly one toggle, after preparation recovers');
same(false,ManagementProgress::for($d)['lock']['pending'],'Customer returns to a completed state');
same(true,ManagementEvidence::resolved($op,$d->fresh()),'Confirmed readback releases the toggle');
$nativeSuccessful=true;echo "Unsent lock preparation retries and settles once.\n";
