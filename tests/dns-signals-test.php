<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsQuery,DnsContext,DnsSignals,DnsCollector,DnsSignalLabels,RoutingProfile,WatchDnsTicket};
use Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader;
function answer(string $name, string $type, array $values, int $ttl = 300): DnsAnswer
{
    $q = new DnsQuery($name,$type);
    return !$values ? DnsAnswer::negative($q,$ttl) : DnsAnswer::rrset($q,array_map(fn($value)=>array_merge([
        'host'=>$name,'class'=>'IN','type'=>$type,'ttl'=>$ttl,match($type){'A'=>'ip','AAAA'=>'ipv6',default=>'target'}=>$value],$type==='MX'?['pri'=>0]:[]),$values));
}
function put(array &$answers, string $name, string $type, array $values, int $ttl = 300): void
{ $a = answer($name,$type,$values,$ttl); $answers[$a->query->key()] = $a; }
function axes($r): array { return [$r->dns,$r->website,$r->email,$r->mixed,$r->partial,$r->result()]; }
$p = new RoutingProfile(['ns2.platform.test','ns1.platform.test'],['ips'=>['192.0.2.1','2001:db8::1'],'suffix'=>['web.platform.test']],['suffix'=>['mail.platform.test']]);
foreach ([[['NS1.PLATFORM.TEST.','ns2.platform.test'],'platform'],[['ns1.platform.test'],'external'],[['ns1.platform.test','ns2.platform.test','other.test'],'external'],[[],null]] as [$values,$expected]) {
    $a=[];put($a,'example.com','NS',$values);same($expected,DnsSignals::delegation('example.com',$p,$a)->dns,'Complete exact NS set rule');
}
same(null,DnsSignals::delegation('example.com',$p,[])->dns,'Unavailable NS unknown');
$nxdomain=new DnsQuery('example.com','NS');same(null,DnsSignals::delegation('example.com',$p,[$nxdomain->key()=>DnsAnswer::negative($nxdomain,60,'nxdomain')])->dns,'Proven absent delegation is unknown, never established external DNS');
$a=[];foreach(['CNAME','A','AAAA','MX'] as $type){put($a,'example.com',$type,[]);}
$r=DnsSignals::points(['example.com'],$p,$a);same([null,false,false,false,false,'complete'],axes($r),'Complete empty does not imply hosted email');
put($a,'example.com','A',['192.0.2.1']);put($a,'example.com','MX',['a.mail.platform.test']);
$r=DnsSignals::points(['example.com'],$p,$a);same([null,true,true,false,false,'complete'],axes($r),'Independent website and email positives');
$b=$a;put($b,'example.com','A',['192.0.2.1','198.51.100.2']);expect(DnsSignals::points(['example.com'],$p,$b)->mixed,'Overlapping address set mixed');
$b=$a;put($b,'example.com','MX',['a.mail.platform.test','mail.external.test']);expect(DnsSignals::points(['example.com'],$p,$b)->mixed,'Mixed mail target not exclusive');
$b=$a;put($b,'example.com','MX',['evilmail.platform.test']);same(false,DnsSignals::points(['example.com'],$p,$b)->email,'Suffix requires label boundary');
$b=$a;put($b,'example.com','MX',['.']);same(false,DnsSignals::points(['example.com'],$p,$b)->email,'Null MX false');
$b=$a;unset($b['example.com|MX']);$r=DnsSignals::points(['example.com'],$p,$b);expect($r->website===true && $r->email===null && $r->partial,'Failed MX preserves independent website positive');
$b=$a;unset($b['example.com|AAAA']);$r=DnsSignals::points(['example.com'],$p,$b);expect($r->website===true && $r->partial,'Known address positive survives other family failure');
$b=[];put($b,'example.com','CNAME',[]);put($b,'example.com','A',['198.51.100.1']);put($b,'example.com','MX',[]);
same(null,DnsSignals::points(['example.com'],$p,$b)->website,'Incomplete nonmatch never false');
$b=$a;put($b,'example.com','A',[],1);put($b,'example.com','AAAA',['2001:0db8::1']);same(true,DnsSignals::points(['example.com'],$p,$b)->website,'IPv6 endpoint canonical match');
$chain=[];put($chain,'example.com','MX',[]);put($chain,'example.com','CNAME',['one.external.test']);put($chain,'one.external.test','CNAME',['two.external.test']);put($chain,'two.external.test','CNAME',['tenant.web.platform.test']);
same(true,DnsSignals::points(['example.com'],$p,$chain)->website,'Three CNAME hops approved');
$long=$chain;put($long,'two.external.test','CNAME',['three.external.test']);put($long,'three.external.test','CNAME',['tenant.web.platform.test']);
same(null,DnsSignals::points(['example.com'],$p,$long)->website,'Fourth CNAME hop unknown');
$loop=$chain;put($loop,'one.external.test','CNAME',['example.com']);same(null,DnsSignals::points(['example.com'],$p,$loop)->website,'Loop unknown');
$ttl=$a;foreach($ttl as $key=>$row){$ttl[$key]=answer($row->query->name,$row->query->type,$row->values(),1);}
same(axes(DnsSignals::points(['example.com'],$p,$a)),axes(DnsSignals::points(['example.com'],$p,$ttl)),'TTL jitter cannot change routing axes');
same(DnsSignals::points(['example.com'],$p,$a)->digest(),DnsSignals::points(['example.com'],$p,$ttl)->digest(),'TTL jitter cannot change routing digest');
$ns1=[];$ns2=[];put($ns1,'example.com','NS',['ns1.platform.test','ns2.platform.test']);put($ns2,'example.com','NS',['ns2.platform.test','ns1.platform.test'],1);
same(DnsSignals::delegation('example.com',$p,$ns1)->digest(),DnsSignals::delegation('example.com',$p,$ns2)->digest(),'NS order and TTL are diagnostic only');
$p2=new RoutingProfile(['different.test'],['ips'=>['198.51.100.1']],[]);same(false,DnsSignals::points(['example.com'],$p2,$a)->website,'Current profile controls classification');
$ipMail=new RoutingProfile(['ns1.platform.test'],[],['ips'=>['192.0.2.1']]);$b=[];put($b,'example.com','MX',['mx.external.test']);put($b,'mx.external.test','A',['192.0.2.1','198.51.100.1']);put($b,'mx.external.test','AAAA',[]);
$r=DnsSignals::points(['example.com'],$ipMail,$b);expect($r->email===true && $r->mixed,'Optional MX address rule and mixed set');
unset($b['mx.external.test|A']);same(null,DnsSignals::points(['example.com'],$ipMail,$b)->email,'Unknown MX address cannot imply false');
$foreign=[];put($foreign,'example.com','MX',[]);put($foreign,'example.com','CNAME',[]);put($foreign,'example.com','A',['198.51.100.1']);
foreach(['CNAME','AAAA'] as $type){put($foreign,'www.example.com',$type,[]);}put($foreign,'www.example.com','A',['192.0.2.1']);
$r=DnsSignals::points(['example.com','www.example.com'],$p,$foreign);expect($r->website===true && $r->mixed && $r->partial,'Known foreign address survives failed family and combines with another platform hostname');
$emptyWithPointer=$foreign;put($emptyWithPointer,'example.com','A',[]);put($emptyWithPointer,'example.com','AAAA',[]);
$r=DnsSignals::points(['example.com','www.example.com'],$p,$emptyWithPointer);expect($r->website===true && !$r->mixed,'Proven absence beside platform pointer does not invent another service');
$mailMixed=new RoutingProfile(['ns1.platform.test'],[],['ips'=>['192.0.2.1'],'exact'=>['mx.platform.test']]);$foreign=[];
put($foreign,'example.com','MX',['mx.external.test','mx.platform.test']);put($foreign,'mx.external.test','A',['198.51.100.1']);
$r=DnsSignals::points(['example.com'],$mailMixed,$foreign);expect($r->email===true && $r->mixed && $r->partial,'Known foreign MX address survives failed family and combines with approved MX');
$many=[];$mxHosts=[];for($i=0;$i<5;$i++){$mxHosts[]='mx'.$i.'.external.test';put($many,$mxHosts[$i],'A',['198.51.100.1']);put($many,$mxHosts[$i],'AAAA',[]);}put($many,'example.com','MX',$mxHosts);
foreach(['example.com','www.example.com'] as $host){put($many,$host,'CNAME',[]);put($many,$host,'A',['192.0.2.1']);put($many,$host,'AAAA',[]);}
$cursor=0;$visited=[];
for($job=0;$job<5;$job++){
    // Adversarial resolver order would offset the cursor to the same two MX targets without sorting.
    $order=array_fill(0,5,null);$order[$job%5]=$mxHosts[0];$order[($job+1)%5]=$mxHosts[1];$rest=array_slice($mxHosts,2);
    for($i=0;$i<5;$i++){if($order[$i]===null){$order[$i]=array_shift($rest);}}put($many,'example.com','MX',$order);
    $ticket=new WatchDnsTicket(1,1,'points','synthetic',0,'stamp',1,1,'psl',1,new RoutingProfile(['ns1.platform.test'],['ips'=>['192.0.2.1']],['ips'=>['192.0.2.1']]),['example.com','www.example.com'],$cursor,86400);
    $fake=new FakeDnsReader($many);[$r,$cursor]=(new DnsCollector($fake))->collect($ticket,new DnsContext());
    expect($r->website===true && count($fake->calls)<=10,'Repeated large MX sets leave website opportunity under shared budget');
    foreach($mxHosts as $host){if(in_array($host.'|A',$fake->calls,true)){$visited[$host]=true;}}
}
same(5,count($visited),'Durable cursor rotates MX address work as well as primary hosts');
$deep=[];put($deep,'example.com','MX',['mx1.external.test','mx2.external.test']);
foreach(['mx1.external.test','mx2.external.test'] as $host){put($deep,$host,'A',['198.51.100.1']);put($deep,$host,'AAAA',[]);}
foreach(['CNAME','A','AAAA'] as $type){put($deep,'example.com',$type,[]);}
put($deep,'www.example.com','CNAME',['one.external.test']);put($deep,'one.external.test','CNAME',['two.external.test']);put($deep,'two.external.test','CNAME',['three.external.test']);
put($deep,'three.external.test','CNAME',[]);put($deep,'three.external.test','A',[]);put($deep,'three.external.test','AAAA',['2001:db8::1']);
$deepProfile=new RoutingProfile(['ns1.platform.test'],['ips'=>['2001:db8::1']],['ips'=>['192.0.2.1']]);$cursor=0;$sawIpv6=false;
for($job=0;$job<4;$job++){
    $ticket=new WatchDnsTicket(1,1,'points','synthetic',0,'stamp',1,1,'psl',1,$deepProfile,['example.com','www.example.com'],$cursor,86400);
    $fake=new FakeDnsReader($deep);[$r,$cursor]=(new DnsCollector($fake))->collect($ticket,new DnsContext());
    if(in_array('three.external.test|AAAA',$fake->calls,true)){$sawIpv6=true;expect($r->website===true,'Full permitted CNAME chain discovers platform IPv6');}
    expect(count($fake->calls)<=10,'Deep website plus MX expansion remains bounded');
}
expect($sawIpv6,'Repeated MX expansion cannot permanently starve terminal website address family');
$sourceNumber=1;$sourceCursor=0;$cursor=0;$completedSources=[];
for($job=0;$job<204;$job++){
    $name='host'.$sourceNumber.'.example.com';$rotating=$deep;put($rotating,$name,'CNAME',['one.external.test']);
    $ticket=new WatchDnsTicket(1,1,'points','synthetic',0,'stamp',1,1,'psl',1,$deepProfile,['example.com',$name],$cursor,86400,$sourceCursor,$sourceNumber,true);
    $fake=new FakeDnsReader($rotating);[$r,$cursor,$nextSource]=(new DnsCollector($fake))->collect($ticket,new DnsContext());
    if($nextSource!==$sourceCursor){
        expect($r->website===true && in_array('three.external.test|AAAA',$fake->calls,true),'Overflow source advances only after full permitted website opportunity');
        $completedSources[$sourceNumber]=true;$sourceCursor=$nextSource;$sourceNumber=$sourceNumber%102+1;
    }
    expect(count($fake->calls)<=10,'Even-sized source rotation preserves per-job query ceiling');
}
same(102,count($completedSources),'Even-sized overflow rotation eventually discovers every long-chain IPv6 pointer');


foreach (['nodata','nxdomain'] as $negativeReason) {
    $empties=[];foreach(['CNAME','A','AAAA','MX'] as $type){$q=new DnsQuery('example.com',$type);$empties[$q->key()]=DnsAnswer::negative($q,60,$negativeReason);}
    $fake=new FakeDnsReader($empties);$ticket=new WatchDnsTicket(1,1,'points','synthetic',0,'stamp',1,1,'psl',1,$p,['example.com'],0,86400);
    [$emptyReading]=(new DnsCollector($fake))->collect($ticket,new DnsContext());
    same(false,$emptyReading->website,'Proven negative website via fake reader');same(false,$emptyReading->email,'Proven negative MX via fake reader');
    foreach(['platform'=>'domain.points.platform_empty','external'=>'domain.points.external_empty'] as $dns=>$key){
        expect(in_array($key,DnsSignalLabels::keys($dns,$emptyReading->website,$emptyReading->email,$emptyReading->mixed,$emptyReading->partial),true),'Proven negative fake reaches exact empty label');
    }
}
$hosts=['example.com','www.example.com','extra.example.com','more.example.com'];$b=[];put($b,'example.com','MX',[]);
foreach($hosts as $host){foreach(['CNAME','A','AAAA'] as $type){put($b,$host,$type,[]);}}
$t=new WatchDnsTicket(1,1,'points','synthetic',0,'stamp',1,1,'psl',1,$p,$hosts,0,86400);
$fake=new FakeDnsReader($b);[$r,$cursor]=(new DnsCollector($fake))->collect($t,new DnsContext());
same(10,count($fake->calls),'All primary hosts share ten exchanges');expect($r->website===null && $r->partial,'Omitted hosts remain partial, never false');same(1,$cursor,'Durable rotation successor');
$t2=new WatchDnsTicket(1,1,'points','synthetic',0,'stamp',1,1,'psl',1,$p,$hosts,$cursor,86400);$fake2=new FakeDnsReader($b);(new DnsCollector($fake2))->collect($t2,new DnsContext());
same('www.example.com|CNAME',$fake2->calls[1],'Next rotation gives next hostname an opportunity');
$fake=new FakeDnsReader($long);$t=new WatchDnsTicket(1,1,'points','synthetic',0,'stamp',1,1,'psl',1,$p,['example.com'],0,86400);[$r]=(new DnsCollector($fake))->collect($t,new DnsContext());same(null,$r->website,'Collector cannot follow fourth alias');expect(count($fake->calls)<=10,'Long chain bounded');
$overflowTicket=new WatchDnsTicket(1,1,'points','synthetic',0,'stamp',1,1,'psl',1,$p,['example.com','www.example.com'],0,86400,0,7,true);
$overAnswers=[];put($overAnswers,'example.com','MX',[]);foreach(['example.com','www.example.com'] as $host){foreach(['CNAME','A','AAAA'] as $type){put($overAnswers,$host,$type,[]);}}
[$overReading,,$sourceNext]=(new DnsCollector(new FakeDnsReader($overAnswers)))->collect($overflowTicket,new DnsContext());
expect($overReading->website===null && $overReading->partial,'Source overflow never reports complete nonpointer');same(7,$sourceNext,'Source cursor advances only after selected primary queried');
$exhausted=new DnsContext();for($i=0;$i<10;$i++){$exhausted->reserve();}
[,, $sourceNext]=(new DnsCollector(new FakeDnsReader($overAnswers)))->collect($overflowTicket,$exhausted);same(0,$sourceNext,'Unvisited primary keeps source cursor for next job');
foreach(['platform','external',null] as $dns){foreach([true,false,null] as $w){foreach([true,false,null] as $e){foreach([false,true] as $partial){
    $keys=DnsSignalLabels::keys($dns,$w,$e,false,$partial);$empty=in_array('domain.points.platform_empty',$keys,true)||in_array('domain.points.external_empty',$keys,true);
    same($dns!==null && $w===false && $e===false && !$partial,$empty,'Empty label requires complete known false axes');
}}}}
$knownPartial=DnsSignalLabels::keys('platform',true,null,true,true);
expect(in_array('domain.points.website',$knownPartial,true) && in_array('domain.points.mixed',$knownPartial,true) && in_array('domain.points.partial',$knownPartial,true) && !in_array('domain.points.unknown',$knownPartial,true),'Known partial positive has partial copy without contradicting unavailable copy');
expect(!in_array('domain.points.unknown',DnsSignalLabels::keys(null,true,false),true),'Unknown delegation does not contradict complete positive pointer');
$brokenReader=new class implements Paymenter\Extensions\Servers\Domains\Contracts\DnsReader {
    public int $calls=0;
    public function read(DnsQuery $query,DnsContext $context):DnsAnswer { $this->calls++;return DnsAnswer::unknown($query); }
};
(new DnsCollector($brokenReader))->collect(new WatchDnsTicket(1,1,'points','synthetic',0,'stamp',1,1,'psl',1,$p,$hosts,0,86400),new DnsContext());
same(10,$brokenReader->calls,'Collector bounds reader invocations even when faulty adapter omits context reservation');
// A shared deadline must remain diagnosable after reads, exceptions and before I/O.
foreach (['answer', 'mismatch', 'throw', 'expired'] as $mode) {
    $clock = (object)['now' => 0.0];
    $context = new DnsContext(fn() => $clock->now);
    if ($mode === 'expired') { $clock->now = 45.0; }
    $late = new class($clock, $mode) implements Paymenter\Extensions\Servers\Domains\Contracts\DnsReader {
        public int $calls = 0;
        public function __construct(private object $clock, private string $mode) {}
        public function read(DnsQuery $query, DnsContext $context): DnsAnswer {
            $this->calls++; $context->reserve(); $this->clock->now = 45.0;
            if ($this->mode === 'throw') { throw new RuntimeException('Synthetic resolver failure'); }
            return DnsAnswer::negative($this->mode === 'mismatch' ? new DnsQuery('other.example.com', 'NS') : $query);
        }
    };
    $ticket = new WatchDnsTicket(1,1,'delegation','synthetic',0,'stamp',1,1,'psl',1,$p,['example.com'],0,21600);
    [$reading] = (new DnsCollector($late))->collect($ticket, $context);
    same('unknown', $reading->result(), 'Deadline never preserves usable answer: '.$mode);
    same('deadline', $reading->safeCode(), 'Collector preserves shared deadline reason: '.$mode);
    same($mode === 'expired' ? 0 : 1, $late->calls, 'Pre-read deadline prevents resolver I/O');
}
$retained=['dns'=>['value'=>'platform','available'=>true,'stale'=>false],'website'=>['value'=>false,'available'=>false,'stale'=>true,'mixed'=>true],
    'email'=>['value'=>false,'available'=>true,'stale'=>true,'mixed'=>false],'partial'=>true];
$retainedKeys=DnsSignalLabels::projection($retained);expect(in_array('domain.points.unknown',$retainedKeys,true) && !in_array('domain.points.platform_empty',$retainedKeys,true),'Superseded mixed positive across independent rows remains unknown without invalid label axes');
$keys=DnsSignalLabels::keys('platform',true,false,true);expect(in_array('domain.points.mixed',$keys,true),'Mixed remains visible');
same('Not pointed to Beco',DnsSignalLabels::strings('  ')['domain.points.external_empty'],'Empty company fallback');same('Example DNS',DnsSignalLabels::strings(' Example ')['domain.dns.platform'],'Company trim');
same('Points to Example: website / email',DnsSignalLabels::strings('Example')['domain.points.both'],'Exact company wording');
echo "Exact NS sets, complete/unknown pointers, mixed targets, CNAME bounds, shared budget, rotation and safe labels passed.\n";
