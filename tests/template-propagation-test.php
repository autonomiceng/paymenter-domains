<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{WatchWork,WatchDiscoveryWork,WatchDnsChecks};
extract(dnsFixture());$now=1800000000;$fake=new Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader();$checks=new WatchDnsChecks(true);
$work=new WatchWork($fake,new WatchDiscoveryWork(static fn()=>null),true,fn()=>$now);app()->instance(WatchWork::class,$work);
DB::transaction(fn()=>$profile->update(['enabled'=>true,'revision'=>$profile->revision+1,'reason'=>'Enable explicit template']));$work->enrollWatch($watch->id,$owner->id);
DB::table('domains_watch_work')->where('watch_id',$watch->id)->whereIn('kind',['delegation','points'])->update(['due_at'=>$now+3600,'backoff_until'=>$now+3600,'attempts'=>3]);
DB::transaction(fn()=>$profile->update(['nameservers'=>['ns1.changed.test','ns2.changed.test'],'revision'=>$profile->revision+1,'reason'=>'Edit assigned explicit template']));
$ticket=$checks->begin($watch->id,$owner->id,'delegation',$now);expect($ticket!==null,'Editing an assigned explicit template schedules its DNS work immediately');same((int)$profile->revision,$ticket->profileRevision,'Work uses the saved template revision');same(['ns1.changed.test','ns2.changed.test'],$ticket->profile->nameservers,'Work uses the new records');same([],$fake->calls,'Template edit performs no DNS I/O');
$nativeSuccessful=true;echo "Explicit template changes propagate without waiting for the old DNS retry.\n";
