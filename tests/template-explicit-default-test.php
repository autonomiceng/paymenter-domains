<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,WatchDnsChecks,WatchWork,WatchDiscoveryWork};
use Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader;
// Native after-commit hooks and the durable enrollment cursor determine whether a real read can start.
config(['domains.site_dns_default.nameservers'=>['ns1.beco.test','ns2.beco.test']]);extract(dnsFixture());
$now=1800000000;$reader=new FakeDnsReader();$checks=new WatchDnsChecks(true);
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>null,'profile_revision'=>0,'expected_dns'=>null]);
$work=new WatchWork($reader,new WatchDiscoveryWork(static fn()=>0),true,fn()=>$now);app()->instance(WatchWork::class,$work);
$work->reconcileDefaults();
same(3,DB::table('domains_watch_work')->where('watch_id',$watch->id)->count(),'Enrollment schedules registry and DNS without an outer transaction');
$hold=static function()use($watch,$now):void {DB::table('domains_watch_work')->where('watch_id',$watch->id)->whereIn('kind',['delegation','points'])->update(['due_at'=>$now+3600,'backoff_until'=>$now+3600,'attempts'=>3,'claim'=>null,'lease_until'=>null]);};
$selected=SiteDnsDefault::profile();
for($i=0;$i<25;$i++){DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'page-'.$i.'-placeholder.com','psl_revision'=>str_repeat('a',64),'state'=>'paused','ns_profile_id'=>null,'profile_revision'=>0,'notice_preferences'=>[]]));}
$explicit=DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'explicit-placeholder.com','psl_revision'=>str_repeat('a',64),'state'=>'active','ns_profile_id'=>$selected->id,'profile_revision'=>$selected->revision,'expected_dns'=>'platform','notice_preferences'=>[]]));
$order2=App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);$host2=App\Models\Service::create([...$service->only(['product_id','plan_id','status','quantity','price','currency_code']),'user_id'=>$owner->id,'order_id'=>$order2->id]);
$host2->properties()->create(['key'=>'domain','value'=>'explicit-placeholder.com']);$copy=$source->replicate();$copy->watch_id=$explicit->id;$copy->registrable_name='explicit-placeholder.com';$copy->hostname='explicit-placeholder.com';$copy->service_id=$host2->id;DB::transaction(fn()=>$copy->save());
$work->enrollWatch($explicit->id,$owner->id);DB::table('domains_watch_work')->where('watch_id',$explicit->id)->whereIn('kind',['delegation','points'])->update(['due_at'=>$now+3600,'backoff_until'=>$now+3600]);

DB::transaction(fn()=>$selected->update(['revision'=>$selected->revision+1,'nameservers'=>['ns1.changed.test','ns2.changed.test'],'edited_by'=>$owner->id,'reason'=>'Changed selected nameservers']));
expect($checks->begin($watch->id,$owner->id,'delegation',$now)!==null,'Editing the selected site template still refreshes inherited DNS work');
$work->reconcileDefaults();
expect($checks->begin($explicit->id,$owner->id,'delegation',$now)!==null,'Editing the site template also refreshes explicit assignments to it');
same([],$reader->calls,'Default propagation performs no DNS I/O');$nativeSuccessful=true;echo "Explicit and inherited site-default assignment refresh passed.\n";
