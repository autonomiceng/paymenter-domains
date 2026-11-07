<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
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
$hold();
DB::transaction(fn()=>WatchProfile::create(['name'=>'Unrelated DNS','enabled'=>true,'nameservers'=>['ns1.other.test','ns2.other.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$owner->id,'reason'=>'Another explicit template']));
same(null,$checks->begin($watch->id,$owner->id,'delegation',$now),'An unrelated DNS template must preserve inherited-watch retry backoff');
$hold();SiteDnsDefault::ensure();
same(null,$checks->begin($watch->id,$owner->id,'delegation',$now),'Unchanged setup must preserve inherited-watch retry backoff');
$selected=SiteDnsDefault::profile();
DB::transaction(fn()=>$selected->update(['revision'=>$selected->revision+1,'nameservers'=>['ns1.changed.test','ns2.changed.test'],'edited_by'=>$owner->id,'reason'=>'Changed selected nameservers']));
expect($checks->begin($watch->id,$owner->id,'delegation',$now)!==null,'Editing the selected site template still refreshes inherited DNS work');
$hold();
// Interleave a real default restart after this batch has read its cursor, with eager processing disabled.
app()->instance(WatchWork::class,new WatchWork($reader,new WatchDiscoveryWork(static fn()=>0),false,fn()=>$now));
$restart=true;
DB::listen(static function($query)use(&$restart):void {
    if($restart && str_starts_with($query->sql,'select * from "domains_watch_names" where "id" > ?')){
        $restart=false;SiteDnsDefault::reconcile();
    }
});
$work->reconcileDefaults();expect(!$restart,'Concurrent restart was injected after the enrollment cursor read');
$data=json_decode(DB::table('domains_watch_schedule')->where('scope','enrollment')->value('data'),true);
same(true,$data['refresh_dns']??false,'A newer default restart must survive the older enrollment batch');
$work->reconcileDefaults();
expect($checks->begin($watch->id,$owner->id,'delegation',$now)!==null,'The retained restart refreshes the inherited watch on the next batch');
same([],$reader->calls,'Template maintenance makes no public DNS reads');
$nativeSuccessful=true;echo "Site DNS refresh preserves unrelated work.\n";
