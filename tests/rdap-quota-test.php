<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{RdapQuota,PublicDnsQuota};
extract(dnsFixture());$now=1800000000;$url='https://rdap.example.com/domain/example.com';$q=new RdapQuota();
expect($q->reserve($url,$now),'Host admitted');expect(!(new RdapQuota())->reserve($url,$now),'One in flight per host');$q->complete($url,['status'=>200],$now);
for($i=1;$i<6;$i++){expect($q->reserve($url,$now),'Host minute slot');$q->complete($url,['status'=>200],$now);}expect(!$q->reserve($url,$now),'Host max six');expect($q->reserve($url,$now+60),'Minute resets');$q->complete($url,['status'=>200],$now+60);
DB::table(RdapQuota::TABLE)->delete();for($i=0;$i<30;$i++){$u='https://r'.$i.'.example.com';expect($q->reserve($u,$now),'Global slot');$q->complete($u,['status'=>200],$now);}expect(!$q->reserve('https://extra.example.com',$now),'Global max thirty');
$dns=new PublicDnsQuota();expect($dns->acquire($now)!==null,'HTTP saturation never consumes DNS pool');
DB::table(RdapQuota::TABLE)->delete();for($i=0;$i<5;$i++){expect($q->reserve($url,$now+$i*60),'Endpoint failure probe');$q->complete($url,null,$now+$i*60);}expect(!$q->reserve($url,$now+300),'Five failures open host circuit');expect($q->reserve('https://other.example.com',$now+300),'Other registry unaffected');$q->complete('https://other.example.com',['status'=>200],$now+300);
expect($q->reserve($url,$now+240+3600),'Hourly probe admitted');expect(!(new RdapQuota())->reserve($url,$now+240+3600),'Only one probe in flight');$q->complete($url,['status'=>200],$now+3840);same(0,(int)DB::table(RdapQuota::TABLE)->where('scope',hash('sha256','rdap.example.com'))->value('failures'),'Success resets own endpoint');
DB::table(RdapQuota::TABLE)->delete();expect($q->reserve($url,$now),'429 read admitted');$q->complete($url,['status'=>429,'retry_after'=>'9999999999'],$now);same($now+86400,$q->blockedUntil,'Retry-After capped at day');expect(!$q->reserve($url,$now+86399),'429 wait honored');
same($now+3600,RdapQuota::retryAfter(gmdate('D, d M Y H:i:s \G\M\T',$now+3600),$now),'HTTP date parsed');same(0,RdapQuota::retryAfter('tomorrow',$now),'No permissive date parsing');
DB::table(RdapQuota::TABLE)->delete();$a=new RdapQuota();$b=new RdapQuota();expect($a->reserve($url,$now),'Abandoned request');expect($b->reserve($url,$now+120),'Expired quota lease permits later read');$a->complete($url,null,$now+121);expect(!(new RdapQuota())->reserve($url,$now+121),'Old completion cannot unlock newer token');$b->complete($url,['status'=>200],$now+121);
expect($q->reserve($url,$now+122),'Completed new token releases');$q->complete($url,['deferred'=>true],$now+122);same(0,(int)DB::table(RdapQuota::TABLE)->where('scope',hash('sha256','rdap.example.com'))->value('failures'),'Local DNS budget does not poison endpoint');
DB::table(RdapQuota::TABLE)->delete();for($i=0;$i<5;$i++){expect($q->reserve($url,$now+$i*60),'Malformed reply admitted');$q->complete($url,['status'=>404,'failed'=>true],$now+$i*60);}expect(!$q->reserve($url,$now+300),'Malformed HTML404 contributes to endpoint circuit');
DB::table(RdapQuota::TABLE)->delete();
$bootstrapCalls=0;$bootstrapRequests=[];$bootstrapCompleted=[];
$dns=new Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader([
    'data.iana.org|CNAME'=>static fn($q)=>Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::negative($q,0,'nodata'),
    'data.iana.org|A'=>static fn($q)=>Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::rrset($q,[['host'=>'data.iana.org','class'=>'IN','type'=>'A','ttl'=>60,'ip'=>'8.8.8.8']])]);
for($i=0;$i<5;$i++) {
    $at=$now+$i*60;$reader=new Paymenter\Extensions\Servers\Domains\Services\PublicRegistryReader($dns,null,static function($url,$timeout,$pins)use(&$bootstrapCalls,&$bootstrapRequests){$bootstrapCalls++;$bootstrapRequests[]=[$url,$pins];return ['status'=>200,'body'=>'{}'];});
    $context=new Paymenter\Extensions\Servers\Domains\Dns\RegistryContext(static fn()=>$at,fn($u)=>$q->reserve($u,$at),function($u,$r)use($q,$at,&$bootstrapCompleted){$bootstrapCompleted[]=[$u,$r];$q->complete($u,$r,$at);});
    same('endpoint_failed',$reader->read('example.com',$context)['reason'],'Malformed bootstrap remains unknown');
    same($i+1,$bootstrapCalls,'Malformed bootstrap reaches HTTP exactly once per admitted probe');
    same(['https://data.iana.org/rdap/dns.json',['8.8.8.8']],$bootstrapRequests[$i],'Malformed body comes from public bootstrap endpoint');
    same(['https://data.iana.org/rdap/dns.json',['status'=>200,'retry_after'=>null,'deferred'=>false,'failed'=>true]],$bootstrapCompleted[$i],'Malformed bootstrap200 reaches completion with body failure');
}
expect(!$q->reserve('https://data.iana.org/rdap/dns.json',$now+300),'Malformed bootstrap200 opens endpoint circuit');
$nativeSuccessful=true;echo "Independent RDAP host/global limits, exact leases, circuit, Retry-After and DNS isolation passed.\n";
