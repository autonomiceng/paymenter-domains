<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsQuery,DnsSignals};
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicObservation,WatchName,WatchSource,WatchDnsObservation};
use Paymenter\Extensions\Servers\Domains\Services\{WatchMaintenance,WatchDnsChecks,ManagedPublicWorkSchema};
extract(managedFixture());$now=$time->now;$health=new WatchMaintenance();
DB::table(\Paymenter\Extensions\Servers\Domains\Services\PublicDnsQuota::TABLE)->where('id',1)->update(['window_at'=>$now]);
expect($signals->enqueue($d->id,$owner->id,$now),'Managed DNS work enrolled');
managedPlatformChoice(compact('d','profile','staff','time'));
DB::table('domains_watch_schedule')->updateOrInsert(['scope'=>'dispatch'],['progress_at'=>$now]);
DB::table('domains_watch_schedule')->insert(['scope'=>'health_managed_dns','cursor'=>$now-2*86400]);
foreach(['nxdomain','nodata']as$reason){
    $query=new DnsQuery('example.com','NS');$fake->answers[$query->key()]=DnsAnswer::negative($query,60,$reason);
    managedDue($d->id,$time->now);expect($signals->observe($d->id,$owner->id,'delegation'),'Managed negative DNS answer retained');
    expect(!$health->health($time->now)['outage'],'A valid negative DNS answer must not report a resolver outage');
    $row=ManagedPublicObservation::latest('id')->firstOrFail();
    same([null,'unknown','nameservers_missing'],[$row->dns,$row->result,$row->safe_code],'Absent nameservers never assert external DNS');
    $work=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$d->id)->where('kind','delegation')->sole();
    same([0,$time->now,null],[(int)$work->attempts,(int)$work->last_success_at,$work->backoff_until],'Valid absence clears retry failures');
    same($time->now+21600,(int)$work->due_at,'Valid absence keeps normal delegation cadence');
    $time->now+=60;DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$time->now]);
}
// A separate hosting name uses the same DNS reader without a managed holding.
$service=$host->replicate();$service->save();$service->properties()->create(['key'=>'domain','value'=>'www.other.com']);
$external=DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'other.com','psl_revision'=>str_repeat('a',64),'state'=>'active',
    'expected_dns'=>'platform','ns_profile_id'=>$profile->id,'profile_revision'=>$profile->revision,'notice_preferences'=>[]]));
DB::transaction(fn()=>WatchSource::create(['user_id'=>$owner->id,'service_id'=>$service->id,'map_id'=>$map->id,'map_revision'=>$map->revision,
    'hostname'=>'www.other.com','registrable_name'=>'other.com','watch_id'=>$external->id,'state'=>'watch','source_digest'=>str_repeat('b',64),'last_seen_at'=>$now]));
$checks=new WatchDnsChecks(true);expect($checks->enroll($external->id,$owner->id,$time->now),'External watch enrolled');
DB::table('domains_watch_schedule')->insert(['scope'=>'health_watch_dns','cursor'=>$now-2*86400]);
foreach(['nxdomain','nodata']as$reason){
    DB::table('domains_watch_work')->where('watch_id',$external->id)->where('kind','delegation')->update(['due_at'=>$time->now,'backoff_until'=>null]);
    $ticket=$checks->begin($external->id,$owner->id,'delegation',$time->now);expect($ticket!==null,'External negative check begins');
    $query=new DnsQuery('other.com','NS');$reading=DnsSignals::delegation('other.com',$profile->profile(),[$query->key()=>DnsAnswer::negative($query,60,$reason)]);
    expect($checks->finish($ticket,$reading,$time->now),'External negative answer retained');
    expect(!$health->health($time->now)['outage'],'Negative external answers do not report a DNS outage');
    $row=WatchDnsObservation::where('watch_id',$external->id)->latest('id')->firstOrFail();
    same([null,'unknown','nameservers_missing'],[$row->dns,$row->result,$row->safe_code],'External absence keeps DNS unknown');
    $work=DB::table('domains_watch_work')->where('watch_id',$external->id)->where('kind','delegation')->sole();
    same([0,$time->now,null],[(int)$work->attempts,(int)$work->last_success_at,$work->backoff_until],'External absence is a successful check');
    $time->now+=60;DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$time->now]);
}
// Unknown replies still count as failures, even after a previously successful negative reply.
$time->now+=2*86400;managedDue($d->id,$time->now);$fake->answers['example.com|NS']=DnsAnswer::unknown(new DnsQuery('example.com','NS'),'resolver_failed');
expect($signals->observe($d->id,$owner->id,'delegation'),'Resolver failure retained');
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$time->now]);
expect($health->health($time->now)['outage'],'A real persistent resolver failure still raises an outage');
same('resolver_failed',$health->health($time->now)['resolver_error'],'Real resolver cause remains visible');
$nativeSuccessful=true;echo "Negative nameserver answers remain unknown routing without becoming resolver outages.\n";
