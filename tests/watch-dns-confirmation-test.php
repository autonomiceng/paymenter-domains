<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{WatchDnsChecks,WatchDnsProjection};
use Paymenter\Extensions\Servers\Domains\Dns\{DnsReading,DnsAnswer,DnsQuery};
use Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation;
extract(dnsFixture());$now=1800000000;$id=(int)$watch->id;$uid=(int)$owner->id;DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['enabled'=>true]);$c=new WatchDnsChecks(true);$c->enroll($id,$uid,$now);
$reading=new DnsReading('delegation',dns:'external',queries:[DnsAnswer::rrset(new DnsQuery('example.com','NS'),[['host'=>'example.com','class'=>'IN','type'=>'NS','ttl'=>1200,'target'=>'ns.external.test']])]);
$t=$c->begin($id,$uid,'delegation',$now);expect($c->finish($t,$reading,$now),'First mismatch');$w=DB::table('domains_watch_work')->where('kind','delegation')->sole();same($now+1200,(int)$w->due_at,'Prior positive TTL bounded confirmation');expect(!WatchDnsObservation::latest('id')->first()->facts['ns_confirmed'],'One sample unconfirmed');expect(!(new WatchDnsProjection())->read($id,$uid,$now)['dns']['notice_eligible'],'Unconfirmed cannot notify');
DB::table('domains_watch_work')->where('kind','points')->update(['due_at'=>$now+86400]);$reading=new DnsReading('delegation',dns:'external',queries:[DnsAnswer::rrset(new DnsQuery('example.com','NS'),[['host'=>'example.com','class'=>'IN','type'=>'NS','ttl'=>1200,'target'=>'other.external.test']])]);$t=$c->begin($id,$uid,'delegation',$now+1200);expect($c->finish($t,$reading,$now+1200),'Second same sample');expect(WatchDnsObservation::latest('id')->first()->facts['ns_confirmed'],'Second spaced sample confirmed');same($now+1200,(int)DB::table('domains_watch_work')->where('kind','points')->value('due_at'),'Early points coalesced after confirmation');expect((new WatchDnsProjection())->read($id,$uid,$now+1200)['dns']['notice_eligible'],'Fresh confirmed notice eligible');expect(!(new WatchDnsProjection())->read($id,$uid,$now+1200+43200)['dns']['notice_eligible'],'Expired DNS cannot notify');
DB::table('domains_watch_work')->where('kind','delegation')->update(['due_at'=>$now+1300]);$t=$c->begin($id,$uid,'delegation',$now+1300);expect(!$c->finish($t,$reading,$now+1345),'Late NS refresh discarded');
DB::table('domains_watch_work')->where('kind','delegation')->update(['due_at'=>$now+1346,'backoff_until'=>null]);$t=$c->begin($id,$uid,'delegation',$now+1346);$c->defer($t,$now+1346);expect(!(new WatchDnsProjection())->read($id,$uid,$now+1347)['dns']['notice_eligible'],'Quota cannot revive deadline-failed NS evidence');
// An overflowing source cohort must stay fixed for apex-only delegation confirmation.
for($i=0;$i<100;$i++) {
    $extra=$service->replicate();$extra->save();$extra->properties()->create(['key'=>'domain','name'=>'Domain','value'=>'www.example.com']);
    DB::transaction(fn()=>Paymenter\Extensions\Servers\Domains\Models\WatchSource::create(['user_id'=>$uid,'service_id'=>$extra->id,'map_id'=>$map->id,'map_revision'=>1,'hostname'=>'www.example.com','registrable_name'=>'example.com','watch_id'=>$id,'state'=>'watch','source_digest'=>str_repeat('b',64),'last_seen_at'=>$now]));
}
$collector=new Paymenter\Extensions\Servers\Domains\Dns\DnsCollector(new Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader(['example.com|NS'=>static fn($q)=>DnsAnswer::rrset($q,[['host'=>'example.com','class'=>'IN','type'=>'NS','target'=>'overflow.external.test','ttl'=>900]])]));
DB::table('domains_watch_work')->where('kind','delegation')->update(['due_at'=>$now+2400,'backoff_until'=>null]);
foreach([$now+2400,$now+3300]as$at) {
    $t=$c->begin($id,$uid,'delegation',$at);expect($t->sourceOverflow,'Overflow ticket');
    [$r,$cursor,$sourceCursor]=$collector->collect($t,new Paymenter\Extensions\Servers\Domains\Dns\DnsContext(static fn()=>$at));
    same($t->sourceCursor,$sourceCursor,'Apex-only read keeps source cohort stable');expect($c->finish($t,$r,$at,$cursor,$sourceCursor),'Overflow delegation committed');
}
expect(WatchDnsObservation::latest('id')->first()->facts['ns_confirmed'],'Overflow apex mismatch confirms');
$nativeSuccessful=true;echo "Watch delegation confirmation, TTL cadence, early points and stale notice eligibility passed.\n";
