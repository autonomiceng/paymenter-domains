<?php
// Installed counter decisions under overlapping processes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCalls,EnomTestCallLimit,EnomTestCallSchema};
$clock=new class implements Clock { public function now():DateTimeImmutable{return new DateTimeImmutable('2026-10-23T12:00:00Z');} };
app()->instance(Clock::class,$clock);config(['domains.enom_test_daily_call_cap'=>7]);
$worker=(int)getenv('PACKAGE_RACE_WORKER');$barriers=0;$barrierFailures=[];
if($worker!==0){
    expect(in_array($worker,[1,2],true),'Known counter worker');
    // insertOrIgnore has committed before this event. Both processes meet here
    // before each conditional increment, including the first absent-row decision.
    DB::listen(static function($event)use($worker,$receipt,&$barriers,&$barrierFailures):void {
        if(!str_starts_with($event->sql,'insert or ignore into "'.EnomTestCallSchema::TABLE.'"')){return;}
        $turn=$barriers++;$ready=$receipt.'.test-calls-ready-';
        file_put_contents($ready.$worker.'-'.$turn,'ready');$peer=$ready.(3-$worker).'-'.$turn;
        $until=microtime(true)+20;
        while(!is_file($peer)&&microtime(true)<$until){usleep(25000);}
        if(!is_file($peer)){$barrierFailures[]=$turn;throw new LogicException('Counter worker did not reach its reservation barrier');}
    });
}
$admitted=0;$refused=0;
for($i=0;$i<20;$i++){
    try{same('2026-10-23',EnomTestCalls::reserve(Environment::Test),'Reserved UTC counter day');++$admitted;}
    catch(EnomTestCallLimit){++$refused;}
}
same([],$barrierFailures,'All competing reservation barriers completed');
same(7,(int)DB::table(EnomTestCallSchema::TABLE)->where('day','2026-10-23')->value('used'),'Concurrent/restarted cap stops exactly at its limit');
if($worker!==0){
    same(20,$barriers,'Each worker meets every reservation decision');
    // Both workers finish each prior decision before the next barrier opens, so six admissions split 3/3 and the seventh makes 3/4.
    expect(in_array($admitted,[3,4],true),'Each overlapping worker reserves three or four units');
    same(20-$admitted,$refused,'Each worker accounts for refused units');
    file_put_contents($receipt.'.test-calls-'.$worker.'.json',json_encode(compact('admitted','refused','barriers'),JSON_THROW_ON_ERROR));
}else{
    same(0,$admitted,'Restart and reinstall cannot admit another unit on the exhausted UTC day');
    same(20,$refused,'Every exhausted-day decision is refused');
}
