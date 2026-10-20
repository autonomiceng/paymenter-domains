<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchSource};
use Paymenter\Extensions\Servers\Domains\Services\{WatchDnsChecks,WatchDnsObserver,PublicDnsQuota,ManagedPublicWorkSchema};
extract(managedFixture());$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now;
$ho=App\Models\Order::create(['user_id'=>$other->id,'currency_code'=>'USD']);
$hs=App\Models\Service::create(['user_id'=>$other->id,'order_id'=>$ho->id,'product_id'=>$host->product_id,'plan_id'=>$host->plan_id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
$hs->properties()->create(['key'=>'domain','value'=>'www.example.com']);
$external=DB::transaction(fn()=>WatchName::create(['user_id'=>$other->id,'canonical_name'=>'example.com','psl_revision'=>str_repeat('a',64),'state'=>'active','ns_profile_id'=>$profile->id,'profile_revision'=>2,'notice_preferences'=>[]]));
DB::transaction(fn()=>WatchSource::create(['user_id'=>$other->id,'service_id'=>$hs->id,'map_id'=>$map->id,'map_revision'=>1,'hostname'=>'www.example.com','registrable_name'=>'example.com','watch_id'=>$external->id,'state'=>'watch','source_digest'=>str_repeat('c',64),'last_seen_at'=>$now]));
$checks=new WatchDnsChecks(true);expect($checks->enroll($external->id,$other->id,$now),'Foreign watch uses its own source');
$observer=new WatchDnsObserver($checks,$fake,fn()=>$time->now);$fake->answers['example.com|NS']=managedNs();$quota=new PublicDnsQuota();
PublicDnsQuota::seed($now);DB::table(PublicDnsQuota::TABLE)->update(['used'=>59]);
expect($signals->observe($id,$uid,'delegation'),'Managed uses final shared DNS slot');same(60,(int)DB::table(PublicDnsQuota::TABLE)->value('used'),'Managed reserves shared slot');$calls=count($fake->calls);
expect(!$observer->observe($external->id,$other->id,'delegation'),'Watch shares exhausted managed pool');same($calls,count($fake->calls),'Shared exhaustion prevents watch wire');
$work=DB::table('domains_watch_work')->where('watch_id',$external->id)->where('kind','delegation')->first();same(0,(int)$work->attempts,'Local saturation is not failure');same($now+60,(int)$work->due_at,'Saturation defers sixty seconds');
$time->now+=60;DB::table(PublicDnsQuota::TABLE)->update(['window_at'=>$time->now,'used'=>59]);
expect($observer->observe($external->id,$other->id,'delegation'),'Watch uses final slot next window');$calls=count($fake->calls);managedDue($id,$time->now);
expect($signals->observe($id,$uid,'delegation'),'Managed saturation records unknown');same($calls,count($fake->calls),'Managed shares exhausted watch pool');
$work=DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','delegation')->first();same(0,(int)$work->attempts,'Managed local saturation keeps failure count');same($time->now+60,(int)$work->due_at,'Managed saturation defers sixty seconds');
$a=$quota->acquire($time->now);$b=$quota->acquire($time->now);expect($a!==null && $b!==null,'Two public active jobs');same(null,$quota->acquire($time->now),'Third public job refused');
managedDue($id,$time->now);expect(!$signals->observe($id,$uid,'delegation'),'Managed cannot borrow a third slot');same($calls,count($fake->calls),'Active saturation performs no wire');
$quota->release($a);$quota->release($b);same([],json_decode(DB::table(PublicDnsQuota::TABLE)->value('active'),true),'Exact public job tokens released');
$time->now+=121;$a=$quota->acquire($time->now);expect($quota->reserve($a,$time->now),'New minute accepts query');expect(!$quota->reserve($a,$now-1),'Clock reversal never resets quota');$quota->release($a);
$nativeSuccessful=true;echo "Watch/managed shared sixty-query pool, two-job limit, local deferral and clock reversal passed.\n";
