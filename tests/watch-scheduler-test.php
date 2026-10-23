<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader;
use Paymenter\Extensions\Servers\Domains\Services\{WatchWork,WatchDiscoveryWork,ManagedPublicReconciliation,ManagedPublicSignals,PublicDnsQuota,WatchRegistryChecks};
use Paymenter\Extensions\Servers\Domains\Jobs\RunPublicCheck;
extract(dnsFixture());$now=1800000000;$fake=new FakeDnsReader();$seen=[];$discovery=new WatchDiscoveryWork(function($id,$product)use(&$seen){$seen[]=$id;});$sent=[];
$off=new WatchWork($fake,$discovery);same(0,$off->tick(),'Fresh install disabled');same(0,DB::table('domains_watch_schedule')->count(),'No dormant enrollment');
$work=new WatchWork($fake,$discovery,true,function()use(&$now){return $now;},function($hint)use(&$sent){$sent[]=$hint;});
same(0,$work->tick(),'Sync queue refused');config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>179]);same(0,$work->tick(),'Retry-after shorter than cleanup refused');config(['queue.connections.database.retry_after'=>180]);same('waiting',$work->status()['reason'],'Active queue without first turn is waiting');
same(1,$work->tick(),'Registry enrollment independent of disabled profile');same([(int)$service->id],$seen,'Discovery invokes local producer');same(1,count($sent),'Due public job dispatched');same(0,$work->tick(),'Same five minute turn coalesced');$now+=299;same(0,$work->tick(),'No early turn');$now++;expect($work->tick()<=10,'Next five minute turn');
$j=new RunPublicCheck(['watch_id'=>$watch->id,'user_id'=>$owner->id,'kind'=>'expiry']);same(120,$j->uniqueFor,'Duplicate hints coalesced');same(60,$j->timeout,'Worker timeout');same(1,$j->tries,'One queue attempt');expect($j->failOnTimeout,'Timeout fails job');
DB::transaction(function()use($work){same(0,$work->tick(),'No dispatcher in native transaction');});
$now+=300;$bad=new WatchWork($fake,new WatchDiscoveryWork(static fn()=>throw new RuntimeException('Synthetic producer')),true,function()use(&$now){return $now;},function($hint)use(&$sent){$sent[]=$hint;});
DB::table('domains_watch_discovery')->update(['cursor'=>0,'due_at'=>$now,'hint'=>true]);$before=count($sent);$bad->tick();expect(count($sent)>$before,'Discovery exception does not prevent public dispatch');same($now,(int)DB::table('domains_watch_schedule')->where('scope','dispatch')->value('progress_at'),'Progress recorded after dispatch');same('current',$bad->status()['reason'],'Recovery tick does not alert for old missed turn');$failure=json_decode(DB::table('domains_watch_schedule')->where('scope','dispatch')->value('data'),true)['local_failures']['discovery'];DB::table('domains_watch_discovery')->update(['due_at'=>$now+86400,'hint'=>false]);$now+=300;$bad->tick();same($failure,json_decode(DB::table('domains_watch_schedule')->where('scope','dispatch')->value('data'),true)['local_failures']['discovery'],'Idle discovery turn retains unresolved producer failure');$now+=600;same('dispatch_overdue',$bad->status()['reason'],'Read-only staff status sees dead worker without a tick');
// Seven owners, both target families, one shared persistent turn cursor.
DB::table('domains_watch_work')->delete();$owners=[];
for($i=0;$i<7;$i++){$u=staffUser([]);$owners[]=(int)$u->id;DB::table('domains_watch_names')->insert(['id'=>100+$i,'user_id'=>$u->id,'canonical_name'=>'fair'.$i.'.com','psl_revision'=>str_repeat('a',64),'state'=>'active','notice_preferences'=>'{}']);foreach(['expiry','delegation','points']as$kind){DB::table('domains_watch_work')->insert(['watch_id'=>100+$i,'kind'=>$kind,'watch_revision'=>1,'due_at'=>$now]);}}
DB::table(PublicDnsQuota::TABLE)->where('id',1)->update(['dispatch_cursor'=>0]);$scheduler=new ManagedPublicReconciliation(new ManagedPublicSignals($fake,false));$a=$scheduler->due($now,true);$b=$scheduler->due($now,true);same(10,count($a),'At most ten due jobs');same(4,count($b),'Owner cursor rotates backlog');same($owners,array_values(array_unique(array_column([...$a,...$b],'user_id'))),'No owner starved');foreach(array_count_values(array_column($a,'user_id'))as$count){same(2,$count,'At most two per owner');}
same([], $scheduler->due($now),'Default managed seam does not activate watches');same(0,DB::table('domains_operations')->count(),'Never registrar operations');
$nativeSuccessful=true;echo "Default-off queue gate, five-minute cadence, shared owner turns and public-only job contract passed.\n";
