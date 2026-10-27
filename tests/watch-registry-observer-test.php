<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/rdap-wire.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsQuery,DnsContext};
use Paymenter\Extensions\Servers\Domains\Services\{WatchRegistryChecks,WatchRegistryObserver,WatchRegistryProjection,PublicRegistryReader,PublicDnsQuota,WatchDnsChecks};
extract(dnsFixture());$now=1800000000;$id=(int)$watch->id;$uid=(int)$owner->id;$reads=0;$wire=0;
$dns=new class implements DnsReader { public function read(DnsQuery $q,DnsContext $c):DnsAnswer { expect(DB::transactionLevel()===0,'No DNS under native locks');if(!$c->reserve()){return DnsAnswer::unknown($q,'budget_exhausted');}return $q->type==='A'?DnsAnswer::rrset($q,[['host'=>$q->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>'8.8.8.8']]):DnsAnswer::negative($q); } };
$bootstrap=['services'=>[[['com'],['https://rdap.example.com']]]];$response=['status'=>200,'body'=>json_encode(['objectClassName'=>'domain','ldhName'=>'example.com','events'=>[['eventAction'=>'expiration','eventDate'=>'2028-01-01']]])];
$bootstrapResponse=['status'=>200,'body'=>json_encode($bootstrap)];
$transport=function($url)use(&$wire,&$response,&$bootstrapResponse){expect(DB::transactionLevel()===0,'No HTTP under native locks');$wire++;return $url===PublicRegistryReader::BOOTSTRAP?$bootstrapResponse:$response;};
$checks=new WatchRegistryChecks(true);$checks->enroll($id,$uid,$now);$observer=new WatchRegistryObserver($checks,$dns,function()use(&$now){return $now;},fn($d,$s)=>new PublicRegistryReader($d,$s,$transport));
expect($observer->observe($id,$uid),'Bootstrap and expiry observed');same(2,$wire,'Bootstrap counts as HTTP');expect(DB::table('domains_watch_schedule')->where('scope','bootstrap')->value('data')!==null,'Validated bootstrap retained');same(6,(int)DB::table(PublicDnsQuota::TABLE)->value('used'),'Endpoint DNS spends shared quota');
$now+=86400;DB::table('domains_watch_work')->where('kind','expiry')->update(['due_at'=>$now]);expect($observer->observe($id,$uid),'Next expiry uses snapshot');same(3,$wire,'Snapshot avoids repeated IANA fetch');
$now+=86400;DB::table('domains_watch_work')->where('kind','expiry')->update(['due_at'=>$now]);$q=new PublicDnsQuota();$a=$q->acquire($now);$b=$q->acquire($now);expect(!$observer->observe($id,$uid),'Existing DNS active job bound applies to RDAP jobs');same(3,$wire,'No HTTP under local saturation');$q->release($a);$q->release($b);
$now+=60;$response=['status'=>429,'body'=>'{}','retry_after'=>'999999'];expect($observer->observe($id,$uid),'Failed exchange recorded');$w=DB::table('domains_watch_work')->where('kind','expiry')->sole();same($now+86400,(int)$w->due_at,'Host Retry-After controls work');expect((new WatchRegistryProjection())->read($id,$uid,$now)['stale'],'Outage retains stale fact');
// DNS work remains independent while registry is backing off.
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['enabled'=>true]);$dnsChecks=new WatchDnsChecks(true);expect($dnsChecks->enroll($id,$uid,$now),'DNS enrolled during HTTP outage');expect($dnsChecks->begin($id,$uid,'delegation',$now)!==null,'RDAP outage never blocks delegation');same(0,DB::table('domains_operations')->count(),'No registrar operation');
// Expired metadata remains a last-good routing fallback during IANA failures and circuit backoff.
$now+=86401;$response=['status'=>200,'body'=>json_encode(['objectClassName'=>'domain','ldhName'=>'example.com','events'=>[['eventAction'=>'expiration','eventDate'=>'2028-01-01']]])];
$bootstrapResponse=['status'=>500,'body'=>'{}'];DB::table('domains_watch_schedule')->where('scope','bootstrap')->update(['due_at'=>$now-1]);
DB::table('domains_watch_work')->where('kind','expiry')->update(['due_at'=>$now]);$before=$wire;
expect($observer->observe($id,$uid),'IANA failure still checks saved registry route');same($before+2,$wire,'Failed metadata refresh then domain lookup');
expect(!(new WatchRegistryProjection())->read($id,$uid,$now)['stale'],'Saved route produces fresh expiry');same($now-1,(int)DB::table('domains_watch_schedule')->where('scope','bootstrap')->value('due_at'),'Failure does not renew metadata cache');
$host=hash('sha256','data.iana.org');DB::table('domains_public_rdap_pool')->where('scope',$host)->update(['backoff_until'=>$now+3600]);
$now+=60;DB::table('domains_watch_work')->where('kind','expiry')->update(['due_at'=>$now]);$before=$wire;
expect($observer->observe($id,$uid),'Bootstrap circuit does not block saved registry route');same($before+1,$wire,'Only domain request during IANA backoff');
expect(!(new WatchRegistryProjection())->read($id,$uid,$now)['stale'],'Independent registry endpoint remains fresh');
// Use the default pinned transport to distinguish failed empty replies from completed 404s across retries.
$nativeObserver=new WatchRegistryObserver($checks,$dns,function()use(&$now){return $now;});
$rdapCalls=0;$rdapStatus=404;
foreach ([[CURLE_RECV_ERROR,'','unknown'],[CURLE_RECV_ERROR,'{"errorCode":404','unknown'],[CURLE_OK,'','not_registered'],[CURLE_RECV_ERROR,'{"errorCode":404}','not_registered'],[CURLE_RECV_ERROR,'','unknown']] as [$rdapErrno,$rdapBody,$expected]) {
    $now+=100000;
    DB::table('domains_watch_schedule')->where('scope','bootstrap')->update(['due_at'=>$now+604800]);
    DB::table('domains_watch_work')->where('kind','expiry')->update(['due_at'=>$now,'backoff_until'=>null]);
    expect($nativeObserver->observe($id,$uid),'Default transport records each retry');
    $latest=Paymenter\Extensions\Servers\Domains\Models\WatchRegistryObservation::orderByDesc('id')->firstOrFail();
    same($expected,$latest->result,'Watch records unknown for incomplete failed 404s');
    same($expected==='unknown'?'endpoint_failed':'not_registered',$latest->reason,'Watch preserves retryable failure reason');
    same('2028-01-01',(new WatchRegistryProjection())->read($id,$uid,$now)['expiry'],'Failed or absent lookup retains last good expiry');
}
same(5,$rdapCalls,'Each watch cycle performs one bounded HTTP exchange');
$nativeSuccessful=true;echo "Registry observer uses injected DNS, shared public slots, retained bootstrap and independent outage facts.\n";
