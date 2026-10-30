<?php
// Installed send limit decisions under overlapping processes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\{DB,Event};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestSendLimit,EnomTestSendLimitSchema};
$clock=new class implements Clock { public function now():DateTimeImmutable{return new DateTimeImmutable('2026-10-23T12:00:00Z');} };
app()->instance(Clock::class,$clock);config(['domains.enom_test_sends_per_day'=>7,'domains.enom_test_sends_per_minute'=>100000]);
$uid='counter-race-uid';$sends=static fn():int=>DB::table(EnomTestSendLimitSchema::SENDS)->where('account',EnomTestSendLimit::account($uid))->count();
$worker=(int)getenv('PACKAGE_RACE_WORKER');$barriers=0;$barrierFailures=[];
if($worker!==0){
    expect(in_array($worker,[1,2],true),'Known counter worker');
    // Both processes meet after BEGIN and before any lock is taken, so each
    // decision, including the first account-row insert, is contested.
    Event::listen(TransactionBeginning::class,static function()use($worker,$receipt,&$barriers,&$barrierFailures):void {
        $turn=$barriers++;$ready=$receipt.'.test-calls-ready-';
        file_put_contents($ready.$worker.'-'.$turn,'ready');$peer=$ready.(3-$worker).'-'.$turn;
        $until=microtime(true)+20;
        while(!is_file($peer)&&microtime(true)<$until){usleep(25000);}
        if(!is_file($peer)){$barrierFailures[]=$turn;throw new LogicException('Counter worker did not reach its decision barrier');}
    });
}
$admitted=0;$refused=0;
for($i=0;$i<20;$i++){
    try{EnomTestSendLimit::admit(Environment::Test,$uid,'GetBalance');++$admitted;}
    catch(EnomTestCallLimit){++$refused;}
}
same([],$barrierFailures,'All competing decision barriers completed');
same(7,$sends(),'Concurrent/restarted limit stops exactly at its ceiling');
if($worker!==0){
    same(20,$barriers,'Each worker meets every send decision');
    // Both workers finish each prior decision before the next barrier opens, so six admissions split 3/3 and the seventh makes 3/4.
    expect(in_array($admitted,[3,4],true),'Each overlapping worker admits three or four sends');
    same(20-$admitted,$refused,'Each worker accounts for refused sends');
    file_put_contents($receipt.'.test-calls-'.$worker.'.json',json_encode(compact('admitted','refused','barriers'),JSON_THROW_ON_ERROR));
}else{
    same(0,$admitted,'Restart and reinstall cannot admit another send inside the 24-hour window');
    same(20,$refused,'Every exhausted-window decision is refused');
}
