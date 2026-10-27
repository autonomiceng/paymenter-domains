<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsQuery,DnsContext,RegistryContext};
use Paymenter\Extensions\Servers\Domains\Services\PublicRegistryReader;
$dns = new class implements DnsReader {
    public array $calls = []; public bool $private = false; public array $aliases = []; public ?string $cnameFailure = null; public bool $unknown6 = false; public bool $mixed = false;
    public function read(DnsQuery $q, DnsContext $c): DnsAnswer {
        $this->calls[] = $q->name.'|'.$q->type;
        if (!$c->reserve()) { return DnsAnswer::unknown($q, $c->live()?'budget_exhausted':'deadline'); }
        if ($q->type === 'CNAME' && $this->cnameFailure!==null) { return DnsAnswer::unknown($q,$this->cnameFailure); }
        if ($q->type === 'CNAME') { return isset($this->aliases[$q->name]) ? DnsAnswer::rrset($q, [['host'=>$q->name,'type'=>'CNAME','class'=>'IN','ttl'=>60,'target'=>$this->aliases[$q->name]]]) : DnsAnswer::negative($q); }
        if ($q->type === 'AAAA' && $this->unknown6) { return DnsAnswer::unknown($q, 'ambiguous_empty'); }
        if ($q->type === 'A' && $this->mixed) { return DnsAnswer::rrset($q, array_map(fn($ip)=>['host'=>$q->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>$ip], ['8.8.8.8','10.0.0.1'])); }
        return $q->type === 'AAAA' ? DnsAnswer::negative($q) : DnsAnswer::rrset($q, [['host'=>$q->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>$this->private?'127.0.0.1':'8.8.8.8']]);
    }
};
$bootstrap = ['services'=>[[['com'],['https://rdap.example.com/']]]];
$body = ['objectClassName'=>'domain','ldhName'=>'example.com','entities'=>[['private'=>'discard']],
    'events'=>[['eventAction'=>'expiration','eventDate'=>'2027-01-01']]];
$calls=[];
$transport = function($url,$timeout,$pins)use(&$calls,&$body,$bootstrap) {
    $calls[]=[$url,$timeout,$pins];same(['8.8.8.8'],$pins,'Public DNS is pinned for each exchange');expect($timeout>0&&$timeout<=8000,'Bounded HTTP timeout');
    return ['status'=>200,'body'=>json_encode($url===PublicRegistryReader::BOOTSTRAP?$bootstrap:$body)];
};
$reader=new PublicRegistryReader($dns,$bootstrap,$transport);$ctx=new RegistryContext(fn()=>10.0);
$r=$reader->read('example.com',$ctx);same('facts',$r['result'],'Expiry does not require transfer registration dates or status');same('date',$r['precision'],'Date precision retained');same('2027-01-01',$r['expiry'],'Date retained');same(1,$ctx->used(),'Snapshot avoids bootstrap request');expect(!str_contains(json_encode($r),'private'),'Entities discarded');
$body['events'][0]['eventDate']='2027-01-01T01:00:00+01:00';$r=$reader->read('example.com',new RegistryContext());same('2027-01-01T00:00:00.000000Z',$r['expiry'],'Instant normalized without dropping precision');
$good=$body;
foreach (['wrong_name','missing','duplicate','redacted','invalid_date','update_only','invalid_offset'] as $case) {
    $body=$good;
    switch($case){case 'wrong_name':$body['ldhName']='other-placeholder.com';break;case 'missing':unset($body['events']);break;
        case 'duplicate':$body['events'][]=$body['events'][0];break;case 'redacted':unset($body['events'][0]['eventDate']);break;
        case 'invalid_date':$body['events'][0]['eventDate']='2027-02-30';break;case 'update_only':$body['events'][0]['eventAction']='last changed';break;
        case 'invalid_offset':$body['events'][0]['eventDate']='2027-01-01T00:00:00+24:00';}
    same('unknown',$reader->read('example.com',new RegistryContext())['result'],'Unknown: '.$case);
}
foreach ([''=>true,'{}'=>true,'{"errorCode":404}'=>true,'{"title":"Not found"}'=>true,'<html>404</html>'=>false,'{"errorCode":500}'=>false,'{"errorCode":"404"}'=>false,'{"errorCode":null}'=>false,'{"errorCode":404.0}'=>false,'[]'=>false,'null'=>false,'{'=>false] as $reply=>$missing) {
    $rdap=new PublicRegistryReader($dns,$bootstrap,fn()=>['status'=>404,'body'=>$reply]);
    same($missing?'not_registered':'unknown',$rdap->read('example.com',new RegistryContext())['result'],'Strict selected-endpoint 404: '.$reply);
}
$rdap=new PublicRegistryReader($dns,null,fn()=>['status'=>404,'body'=>'{}']);same('expiry_unknown',$rdap->read('example.com',new RegistryContext())['safe_code'],'Bootstrap404 is never not registered');
$completed = []; $retryCalls = 0;
$ctx = new RegistryContext(fn()=>10.0, null, function($url,$response) use (&$completed) { $completed[] = [$url,$response]; });
$limited = new PublicRegistryReader($dns,$bootstrap,function() use (&$retryCalls) { $retryCalls++; return ['status'=>429,'body'=>'{}','retry_after'=>'120']; });
same('unknown',$limited->read('example.com',$ctx)['result'],'429 cannot supply registry facts');
same(1,$retryCalls,'429 is never retried within a read');
same([['https://rdap.example.com/domain/example.com',['status'=>429,'retry_after'=>'120','deferred'=>false,'failed'=>true]]],$completed,'Retry-After and completion metadata reach endpoint quota callback');
foreach ([302,429,500] as $status) { same('unknown',(new PublicRegistryReader($dns,$bootstrap,fn()=>['status'=>$status,'body'=>'{}']))->read('example.com',new RegistryContext())['result'],'No HTTP retry or redirect'); }
foreach (['http://rdap.example.com','https://127.0.0.1','https://rdap.local','https://rdap.example.com:444','https://a:b@rdap.example.com','https://rdap.example.com/?url=x'] as $url) {
    $count=count($calls);same('no_coverage',(new PublicRegistryReader($dns,['services'=>[[['com'],[$url]]]],$transport))->read('example.com',new RegistryContext())['safe_code'],'Unsafe bootstrap endpoint');same($count,count($calls),'Unsafe endpoint sends nothing');
}
$dns->private=true;$count=count($calls);same('unknown',$reader->read('example.com',new RegistryContext())['result'],'Private endpoint resolution refused');same($count,count($calls),'No wire to private answer');$dns->private=false;
same('no_coverage',$reader->read('example.zz',new RegistryContext())['safe_code'],'Missing ccTLD remains unknown');
$body=$good;$count=count($calls);$ctx=new RegistryContext(fn()=>10.0,fn()=>false);same('budget_exhausted',$reader->read('example.com',$ctx)['safe_code'],'Admission denial is local deferral');same($count,count($calls),'Reserve before wire');
$ctx=new RegistryContext(fn()=>10.0);for($i=0;$i<4;$i++){$reader->read('example.com',$ctx);}same(3,$ctx->used(),'Three HTTP request bound across reads');same(9,$ctx->dns->used(),'HTTP bound checked before DNS');
$ctx=new RegistryContext(fn()=>10.0);$r=(new PublicRegistryReader($dns,null,$transport))->read('example.com',$ctx);same('facts',$r['result'],'IANA bootstrap lookup');same(2,$ctx->used(),'Bootstrap included in HTTP bound');same(6,$ctx->dns->used(),'Endpoint resolution included in DNS bound');
$ctx=new RegistryContext(fn()=>10.0);same('unknown',(new PublicRegistryReader($dns,$bootstrap,fn()=>['status'=>200,'body'=>str_repeat('x',2097153)]))->read('example.com',$ctx)['result'],'Oversize is unknown');
$ctx=new RegistryContext(fn()=>10.0);same('unknown',(new PublicRegistryReader($dns,$bootstrap,fn()=>throw new RuntimeException('synthetic TLS failure')))->read('example.com',$ctx)['result'],'TLS failure stays unknown');
$body=$good;$body['status']=['<script>','active'];same(['active'],$reader->read('example.com',new RegistryContext())['status'],'Invalid optional statuses omitted');
$dns->aliases=['rdap.example.com'=>'edge.example.com','edge.example.com'=>'last.example.com','last.example.com'=>'final.example.com'];
$ctx=new RegistryContext(fn()=>10.0);same('facts',$reader->read('example.com',$ctx)['result'],'Three aliases keep original HTTP host and final public pins');same(6,$ctx->dns->used(),'Alias hops share query budget');
$dns->aliases['final.example.com']='extra.example.com';same('unknown',$reader->read('example.com',new RegistryContext())['result'],'Fourth alias refused');
$dns->aliases=['rdap.example.com'=>'rdap.example.com'];same('unknown',$reader->read('example.com',new RegistryContext())['result'],'Alias loop refused');
$dns->aliases=[];$dns->unknown6=true;same('facts',$reader->read('example.com',new RegistryContext())['result'],'Known public IPv4 is sufficient with ambiguous IPv6 absence');$dns->unknown6=false;
$dns->mixed=true;same('unknown',$reader->read('example.com',new RegistryContext())['result'],'Mixed public private answers refused');$dns->mixed=false;
foreach ([[],['127.0.0.1'],['::ffff:8.8.8.8'],['100.64.0.1']] as $pins) { $threw=false;try{(new Paymenter\Extensions\Servers\Domains\Services\Rdap())->pinned('https://rdap.example.com',1,$pins);}catch(Throwable){$threw=true;}expect($threw,'Invalid pins refused before curl'); }
$done=[];$ctx=new RegistryContext(fn()=>10.0,null,function($url,$r)use(&$done){$done[]=$r;});(new PublicRegistryReader($dns,$bootstrap,fn()=>throw new RuntimeException()))->read('example.com',$ctx);same([null],$done,'Failed transport completes endpoint quota without raw data');
$body=$good;$body['ldhName']='example.com.';same('unknown',$reader->read('example.com',new RegistryContext())['result'],'Trailing dot identity is not exact');
foreach ([[],['services'=>[]],['services'=>[...$bootstrap['services'],...$bootstrap['services']]]] as $bad) { same('endpoint_failed',(new PublicRegistryReader($dns,$bad,$transport))->read('example.com',new RegistryContext())['reason'],'Malformed bootstrap is retryable'); }
foreach (['invalid_answer'=>'endpoint_failed','budget_exhausted'=>'deferred','deadline'=>'deferred'] as $code=>$reason) { $dns->cnameFailure=$code;same($reason,$reader->read('example.com',new RegistryContext())['reason'],'CNAME failure cannot fall through to public address'); }$dns->cnameFailure=null;
$body=$good;$body['events']=['key'=>$body['events'][0]];same('unknown',$reader->read('example.com',new RegistryContext())['result'],'Events must be a JSON list');
same('endpoint_failed',(new PublicRegistryReader($dns,['services'=>[[[123],['https://rdap.example.com']]]],$transport))->read('example.com',new RegistryContext())['reason'],'Non-string bootstrap suffix refused');
$ctx=new RegistryContext(fn()=>10.0);for($i=0;$i<9;$i++){$ctx->dns->reserve();}same('deferred',$reader->read('example.com',$ctx)['reason'],'Budget exhausted during pinning is local deferral');
$numericEvents='{"objectClassName":"domain","ldhName":"example.com","events":{"0":{"eventAction":"expiration","eventDate":"2027-01-01"}}}';
same('unknown',(new PublicRegistryReader($dns,$bootstrap,fn()=>['status'=>200,'body'=>$numericEvents]))->read('example.com',new RegistryContext())['result'],'Numeric-key objects remain objects');
$numericServices='{"services":{"0":[["com"],["https://rdap.example.com"]]}}';same('endpoint_failed',(new PublicRegistryReader($dns,null,fn()=>['status'=>200,'body'=>$numericServices]))->read('example.com',new RegistryContext())['reason'],'Numeric bootstrap object refused');
$clock=10;$done=[];$ctx=new RegistryContext(function()use(&$clock){return $clock;},null,function($url,$r)use(&$done){$done[]=$r;});$r=(new PublicRegistryReader($dns,$bootstrap,function()use(&$clock){$clock=55;throw new RuntimeException();}))->read('example.com',$ctx);same('deferred',$r['reason'],'Transport exception at deadline is local deferral');same(true,$done[0]['deferred'],'Deadline completion does not poison endpoint');
echo "Public registry expiry, precision, identity, strict404, pinning, budgets and coverage passed.\n";
