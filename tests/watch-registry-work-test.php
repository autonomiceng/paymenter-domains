<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{WatchRegistryChecks,WatchRegistryProjection,WatchRegistryObserver,WatchScheduleSchema,WatchDnsChecks,RdapQuota,PublicRegistryReader};
use Paymenter\Extensions\Servers\Domains\Models\WatchRegistryObservation;
extract(dnsFixture());$now=1800000000;$id=(int)$watch->id;$uid=(int)$owner->id;
$checks=new WatchRegistryChecks(true);expect(!(new WatchRegistryChecks())->enroll($id,$uid,$now),'Default off');
expect($checks->enroll($id,$uid,$now),'Registry can enroll without enabled DNS profile');
expect(!$checks->enroll($id,(int)$other->id,$now),'Foreign owner refused');
$before=(array)DB::table('domains_watch_work')->where('kind','expiry')->sole();same(null,$checks->begin($id,(int)$other->id,$now),'Foreign registry claim refused');same($before,(array)DB::table('domains_watch_work')->where('kind','expiry')->sole(),'Foreign registry caller cannot alter work');
$good=['result'=>'facts','reason'=>'facts','safe_code'=>null,'expiry'=>'2028-01-01','precision'=>'date','source'=>'https://rdap.example.com','status'=>[]];
$revision=str_repeat('c',64);$t=$checks->begin($id,$uid,$now);expect($t!==null,'Claim captured');expect($checks->begin($id,$uid,$now)===null,'Single active claim');
expect($checks->finish($t,$good,$revision,$now+1),'Fact committed');$w=DB::table('domains_watch_work')->where('kind','expiry')->sole();
expect($w->due_at>=$now+1+86400&&$w->due_at<=$now+1+90000,'Daily jitter bound');$p=new WatchRegistryProjection();expect($p->read($id,$uid,$now+2)['notice_eligible'],'Fresh fact eligible');
expect($p->read($id,$uid,$now+172800)['stale'],'Expiry stale at 48h');expect(!$p->read($id,$uid,$now+172800)['notice_eligible'],'Stale cannot notify');same(null,$p->read($id,(int)$other->id,$now),'Owner scoped');
function dueRegistry(int $id,int $now):void { DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['due_at'=>$now,'backoff_until'=>null]); }
dueRegistry($id,$now+3);$wait=$checks->begin($id,$uid,$now+3);$checks->finish($wait,['reason'=>'deferred'],$revision,$now+3);expect($p->read($id,$uid,$now+4)['notice_eligible'],'Local quota wait preserves fresh expiry eligibility');
$now+=100;dueRegistry($id,$now);$old=$checks->begin($id,$uid,$now);$new=$checks->begin($id,$uid,$now+120);same(null,$new,'Expired claim takes backoff before another read');$retry=(int)DB::table('domains_watch_work')->where('kind','expiry')->value('due_at');expect($retry>=$now+1020&&$retry<=$now+1110,'Abandoned read backs off fifteen minutes');$now=$retry-120;$new=$checks->begin($id,$uid,$retry);expect($new!==null,'Crash permits later read after lease and backoff');
expect(!$checks->finish($old,$good,$revision,$now+121),'Old claim cannot overwrite new claim');same($new->claim,DB::table('domains_watch_work')->where('kind','expiry')->value('claim'),'New claim retained');
expect($checks->finish($new,$good,$revision,$now+121),'New read succeeds');same(0,DB::table('domains_operations')->count(),'No registrar operation minted');
$now+=300;dueRegistry($id,$now);$t=$checks->begin($id,$uid,$now);$property->value='changed.example.com';$property->save();expect(!$checks->finish($t,$good,$revision,$now+1),'Native property change discards result');$property->value='www.example.com';$property->save();
$unknown=array_replace($good,['result'=>'unknown','reason'=>'endpoint_failed','safe_code'=>'expiry_unknown','expiry'=>null,'precision'=>null]);
foreach([900,3600,21600,86400]as$delay){$now+=100000;dueRegistry($id,$now);$t=$checks->begin($id,$uid,$now);expect($checks->finish($t,$unknown,$revision,$now),'Failure retained');$w=DB::table('domains_watch_work')->where('kind','expiry')->sole();expect($w->due_at>=$now+$delay && $w->due_at<=$now+(int)($delay*1.1),'Independent per-kind retry bound');}
same('2028-01-01',$p->read($id,$uid,$now)['expiry'],'Outage keeps last good date');expect(!$p->read($id,$uid,$now)['notice_eligible'],'Outage cannot notify');
$now+=100000;dueRegistry($id,$now);$t=$checks->begin($id,$uid,$now);$attempts=$w->attempts;expect(!$checks->finish($t,['reason'=>'deferred'],$revision,$now),'Saturation defers');$w=DB::table('domains_watch_work')->where('kind','expiry')->sole();same((int)$attempts,(int)$w->attempts,'Deferral never fails');same($now+60,(int)$w->due_at,'Deferral 60 seconds');
foreach(['no_expiry'=>86400,'no_coverage'=>604800,'not_registered'=>86400]as$reason=>$delay){$now+=100000;dueRegistry($id,$now);$t=$checks->begin($id,$uid,$now);$r=array_replace($unknown,['reason'=>$reason,'result'=>$reason==='not_registered'?'not_registered':'unknown','safe_code'=>$reason]);$checks->finish($t,$r,$revision,$now);$w=DB::table('domains_watch_work')->where('kind','expiry')->sole();expect($w->due_at>=$now+$delay&&$w->due_at<=$now+$delay+($reason==='no_coverage'?0:3600),'Semantic gap cadence: '.$reason);expect(!$p->read($id,$uid,$now)['notice_eligible'],'Semantic gaps cannot create notice');same(0,(int)$w->attempts,'Valid exchange resets own attempts');}
$now+=100000;dueRegistry($id,$now);$t=$checks->begin($id,$uid,$now);$r=array_replace($good,['expiry'=>gmdate('Y-m-d',$now+20*86400)]);$checks->finish($t,$r,$revision,$now);$w=DB::table('domains_watch_work')->where('kind','expiry')->sole();same($now+21600,(int)$w->due_at,'Urgent every six hours');expect($p->read($id,$uid,$now+43200)['stale'],'Urgent stale after twelve hours');
$now+=100000;dueRegistry($id,$now);$t=$checks->begin($id,$uid,$now);$r=array_replace($good,['expiry'=>gmdate('Y-m-d\TH:i:s\Z',$now+30*86400+3600),'precision'=>'instant']);$checks->finish($t,$r,$revision,$now);expect($p->read($id,$uid,$now+13*3600)['stale'],'Crossing into urgent period reevaluates freshness');expect(!$p->read($id,$uid,$now+13*3600)['notice_eligible'],'Urgent crossing cannot notify from 13h-old evidence');
$now+=100000;dueRegistry($id,$now);$t=$checks->begin($id,$uid,$now);$count=WatchRegistryObservation::count();expect(!$checks->finish($t,$good,$revision,$now+45),'Exact deadline refuses');same($count,WatchRegistryObservation::count(),'Late fact not appended');
dueRegistry($id,$now+46);$t=$checks->begin($id,$uid,$now+46);$checks->finish($t,['reason'=>'deferred'],$revision,$now+46);expect(!$p->read($id,$uid,$now+47)['notice_eligible'],'Quota deferral cannot revive evidence after deadline failure');
$last=WatchRegistryObservation::orderByDesc('id')->first();dnsRefused(fn()=>$last->update(['result'=>'unknown']),'Immutable facts');expect(!str_contains($last->getRawOriginal('facts'),'2028-01-01'),'Facts encrypted');
$entry->installed();WatchScheduleSchema::verify();$table=WatchScheduleSchema::tables()[0];Schema::drop($table);dnsRefused(fn()=>$entry->installed(),'Recorded missing schema refused',false);
$nativeSuccessful=true;echo "Registry claim, crash, deadline, ownership, cadence, independent retries, freshness and retained schema passed.\n";
