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
// Several older managed reads for this owner used to consume whole minute turns before the watch.
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['enabled'=>true]);
for($i=0;$i<2;$i++){
    // Privileged queue fixture: only dispatch eligibility is exercised, never registrar execution.
    $d=Paymenter\Extensions\Servers\Domains\Models\Domain::withoutEvents(fn()=>Paymenter\Extensions\Servers\Domains\Models\Domain::create(['service_id'=>1000+$i,'user_id'=>$owner->id,'server_id'=>$server->id,
        'registrar_key'=>'enom','account_fingerprint'=>str_repeat('a',64),'domain'=>'dispatch'.$i.'.test',
        'sld'=>'dispatch'.$i,'tld'=>'test','status'=>'active','authority_generation'=>1]));
    DB::table('domains_managed_public_config')->insert(['domain_id'=>$d->id,'user_id'=>$owner->id,'profile_id'=>$profile->id,'notice_preferences'=>'{}']);
    foreach(['delegation','points']as$kind){DB::table('domains_managed_public_work')->insert(['domain_id'=>$d->id,'user_id'=>$owner->id,
        'generation'=>1,'account_fingerprint'=>$d->account_fingerprint,'kind'=>$kind,'due_at'=>$now-600,'target_stamp'=>str_repeat('b',64),'safe_code'=>'ready']);}
}
DB::table('domains_watch_work')->where('watch_id',$watch->id)->update(['due_at'=>$now+86400]);
$shown=$now+1;
DB::table('domains_watch_work')->where('watch_id',$watch->id)->whereIn('kind',['delegation','points'])->update(['due_at'=>$shown]);
$sent=[];$now+=60;
same(2,$work->tick(),'Shared owner turn dispatches both due watch DNS reads despite older managed backlog');
same(['delegation','points'],array_column($sent,'kind'),'Both promised watch checks are dispatched together');
expect(count(array_filter($sent,fn($h)=>($h['watch_id']??null)===$watch->id))===2,'Managed backlog does not consume the watch deadline turn');
same(59,$now-$shown,'Shared-turn watch dispatch remains within the next minute');
same(0,$work->tick(),'Shared-turn cap cannot be bypassed by repeated ticks');
// After those watch reads complete, their next pacing window leaves the existing managed backlog runnable.
DB::table('domains_watch_work')->where('watch_id',$watch->id)->update(['due_at'=>$now+86400]);
$sent=[];$now+=60;same(2,$work->tick(),'Managed work still receives the same bounded owner turn');
expect(count(array_filter($sent,fn($h)=>isset($h['domain_id'])))===2,'Managed backlog remains eligible');
$nativeSuccessful=true;echo "Minute dispatch of independently due watch work passed.\n";
