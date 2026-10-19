<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';require __DIR__.'/Support/WireLimit.php';
use Paymenter\Extensions\Servers\Domains\Services\Runtime;
[$d,$driver]=$holding('capacity-management.com');expect(in_array('lock',$management->available($user,$d,$driver,['lock']),true),'Supported owner action available without a transaction');$op=$submit($d,$driver,'lock',['locked'=>false]);
app()->instance(Runtime::class,new Runtime);$limit=new WireLimit(0);app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,$limit);
$result=$management->run($op->id,$driver);same([],$state->calls,'Preparation capacity refusal has no exchanges');same('queued',$result->state,'Preparation read defers');
$limit->allowed=2;$result=$management->run($op->id,$driver);same(['GetDomainInfo'],array_column($state->calls,0),'Only preparation read; no management write');same('queued',$result->state,'Write capacity refusal defers');same('absent',$result->attempt['seal'],'No false management seal');
$limit->allowed=100;$delay=false;$limit->onAcquire=function()use(&$delay,$clock){$delay=true;};Illuminate\Support\Facades\DB::listen(function()use(&$delay,$clock){if($delay){$delay=false;$clock->instant=$clock->now()->modify('+31 seconds');}});$result=$management->run($op->id,$driver);same('queued',$result->state,'Lock delay releases unsealed write');same('absent',$result->attempt['seal'],'Expired reservation cannot seal');same(0,count(array_filter($state->calls,fn($c)=>$c[0]==='SetRegLock')),'No exchange after lease expiry');$limit->onAcquire=null;
$limit->allowed=100;Illuminate\Support\Facades\Cache::flush();$result=$management->run($op->id,$driver);same('succeeded',$result->state,'Management recovers after capacity returns');same(1,count(array_filter($state->calls,fn($c)=>$c[0]==='SetRegLock')),'One recovered management write');
echo "Installed physical management read/write capacity refusal and recovery passed.\n";$nativeSuccessful=true;
