<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchSource;
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,WatchRegistryChecks};
extract(dnsFixture());$now=1800000000;
require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$o=App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
$active=App\Models\Service::create(['user_id'=>$owner->id,'order_id'=>$o->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
$active->properties()->create(['key'=>'domain','value'=>'mail.example.com']);
DB::transaction(fn()=>WatchSource::create([...$source->only(['user_id','map_id','map_revision','registrable_name','watch_id','state','source_digest','last_seen_at']),'service_id'=>$active->id,'hostname'=>'mail.example.com']));
DB::table('services')->where('id',$service->id)->update(['status'=>'suspended']);
$checks=new WatchRegistryChecks(true);expect($checks->enroll($watch->id,$owner->id,$now),'Active sibling can enroll while another source is suspended');
$ticket=$checks->begin($watch->id,$owner->id,$now);expect($ticket!==null,'Active sibling can claim its ordinary registry read');
expect($checks->finish($ticket,['result'=>'facts','reason'=>'facts','safe_code'=>null,'expiry'=>'2028-01-01','precision'=>'date','source'=>'https://rdap.example.com','status'=>[]],str_repeat('c',64),$now+1),'Native read commits with active-only source cohort');
$dto=(new DomainSummaries)->one($owner,'watch',$watch->id,$now+2);expect($dto!==null,'Watch remains visible');same(false,$dto->freshness['registry']['stale'],'Customer summary agrees with the active source check');same('2028-01-01',$dto->expiry,'Fresh registry fact reaches the customer');same(1,count($dto->relatedServices),'Only active hosting link is offered');
DB::table('services')->where('id',$active->id)->update(['status'=>'suspended']);
same(null,$checks->begin($watch->id,$owner->id,$now+3),'No active source can begin registry work');same(false,$checks->enroll($watch->id,$owner->id,$now+3),'All suspended sources refuse enrollment');
same(null,(new Paymenter\Extensions\Servers\Domains\Services\WatchDnsChecks(true))->registryTarget($watch->id,$owner->id,false),'All suspended sources have no current public target');
$paused=(new DomainSummaries)->one($owner,'watch',$watch->id,$now+3);expect($paused!==null,'Suspended watch remains visible');same([],$paused->relatedServices,'No suspended hosting link is offered');expect($paused->expiry===null||($paused->freshness['registry']['stale']??true),'No current registry fact from suspended sources');
$staff=staffUser(['admin.domains.view','admin.domains.watch.view']);Illuminate\Support\Facades\Auth::setUser($staff);
$context=Paymenter\Extensions\Servers\Domains\Services\DomainSummaryContext::staffWatches([$watch->id]);same([],$context->sources,'Staff context also excludes suspended sources');
echo "Suspended siblings leave active checks and customer facts available.\n";$nativeSuccessful=true;
