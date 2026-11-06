<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The durable work rows and dispatcher gate must run together; a due-selector unit test misses the gate.
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{WatchWork,WatchDiscoveryWork,WatchRegistryChecks};
extract(dnsFixture());$now=1800000000;$sent=[];
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>180]);
$work=new WatchWork(new Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader(),new WatchDiscoveryWork(static fn()=>null),true,
    static function()use(&$now){return $now;},static function($hint)use(&$sent,&$now){$sent[]=['at'=>$now,...$hint];});
$work->enrollWatch($watch->id,$owner->id);
// Public reads can become due between minute boundaries, independently of the last dispatcher turn.
$due=$now+61;
DB::table('domains_watch_work')->where('watch_id',$watch->id)->update(['due_at'=>$now+86400]);
DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','expiry')->update(['due_at'=>$due]);
same(0,$work->tick(),'First turn has no due reads');
$now+=60;same(0,$work->tick(),'A minute turn never dispatches a read before its due time');
$now+=60;same(1,$work->tick(),'The first minute turn after the shown due time dispatches the watch');
same([['at'=>1800000120,'watch_id'=>(int)$watch->id,'user_id'=>(int)$owner->id,'kind'=>'expiry']],$sent,'The due registry read is dispatched within 59 seconds');
same(0,$work->tick(),'Repeated calls in the same minute are coalesced');
same($due,(int)DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','expiry')->value('due_at'),'Dispatch does not rewrite the public check pacing');
same(0,DB::table('domains_operations')->count(),'Public dispatch creates no registrar operations');
$nativeSuccessful=true;echo "Minute dispatch of independently due watch work passed.\n";
