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
$hold=static function()use($watch,$now):void {DB::table('domains_watch_work')->where('watch_id',$watch->id)->whereIn('kind',['delegation','points'])->update(['due_at'=>$now+3600,'backoff_until'=>$now+3600,'attempts'=>3,'claim'=>null,'lease_until'=>null]);};
$hold();
DB::transaction(fn()=>WatchProfile::create(['name'=>'Unrelated DNS','enabled'=>true,'nameservers'=>['ns1.other.test','ns2.other.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$owner->id,'reason'=>'Another explicit template']));
same(null,$checks->begin($watch->id,$owner->id,'delegation',$now),'An unrelated DNS template must preserve inherited-watch retry backoff');
same([],$reader->calls,'Template maintenance makes no public DNS reads');
$nativeSuccessful=true;echo "Site DNS refresh preserves unrelated work.\n";
