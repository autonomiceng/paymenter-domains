<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{WatchWork,WatchDiscoveryWork,WatchDnsChecks,SiteDnsDefault};
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
config(['domains.site_dns_default.nameservers'=>['ns1.platform.test','ns2.platform.test']]);extract(dnsFixture());
$now=1800000000;$checks=new WatchDnsChecks(true);$fake=new Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader();
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>null,'profile_revision'=>0,'expected_dns'=>null]);
$work=new WatchWork($fake,new WatchDiscoveryWork(static fn()=>null),true,fn()=>$now);app()->instance(WatchWork::class,$work);$work->reconcileDefaults();
$errors=[];$interleave=null;
DB::listen(static function($query)use(&$interleave):void{
    if($interleave && str_starts_with($query->sql,'select * from "domains_watch_names" where "id" > ?')){$run=$interleave;$interleave=null;$run();}
});
foreach(['claim','backoff']as$case){
    DB::table('domains_watch_work')->where('watch_id',$watch->id)->update(['claim'=>null,'lease_until'=>null,'due_at'=>$now,'backoff_until'=>null,'attempts'=>0,'safe_code'=>null]);
    DB::table('domains_watch_schedule')->where('scope','enrollment')->update(['cursor'=>0,'data'=>json_encode(['refresh_dns'=>true,'restart'=>$case])]);
    $expected=null;
    $interleave=function()use($work,$checks,$watch,$owner,$now,$case,&$expected):void{
        // The second real caller completes this batch on the unfixed code, then a reader owns its work.
        $work->reconcileDefaults();$ticket=$checks->begin($watch->id,$owner->id,'delegation',$now);expect($ticket!==null,'Interleaved DNS read begins');
        if($case==='backoff'){expect($checks->finish($ticket,new DnsReading('delegation'),$now+1),'Interleaved failure finishes');}
        $expected=(array)DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','delegation')->sole();
    };
    $work->reconcileDefaults();expect($interleave===null,'Real enrollment select interleaved');
    $actual=(array)DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','delegation')->sole();
    if($expected!==$actual){$errors[]=$case.' overwritten by duplicate enrollment refresh';}
}
foreach($errors as$error){echo "FAIL $error\n";}expect($errors===[],implode('; ',$errors));same([],$fake->calls,'Enrollment never performs DNS I/O');
$nativeSuccessful=true;echo "Enrollment batch ownership preserves interleaved DNS claims and retry backoff.\n";
