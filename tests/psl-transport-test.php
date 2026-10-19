<?php
// Synthetic cURL/DNS functions only; no sockets. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services {
    $options=[]; $status=200; $errno=0; $chunks=['synthetic']; $headers=['HTTP/2 200\r\n', 'ETag: "v1"\r\n']; $executions=0;
    function curl_init($url) { return $url; }
    function curl_setopt_array($c, $o) { global $options; $options=$o; return true; }
    function curl_exec($c) {
        global $options,$errno,$chunks,$headers,$executions; $executions++;
        foreach($headers as $line) { if($options[CURLOPT_HEADERFUNCTION]($c,str_replace('\\r\\n',"\r\n",$line))===0){return false;} }
        foreach($chunks as $chunk) { if($options[CURLOPT_WRITEFUNCTION]($c,$chunk)!==strlen($chunk)){return false;} }
        return $errno===0;
    }
    function curl_errno($c) { global $errno; return $errno; }
    function curl_getinfo($c,$key) { global $status; return $status; }
    function curl_close($c) {}
}
namespace {
    require __DIR__.'/bootstrap.php';
    use Paymenter\Extensions\Servers\Domains\Services\{PslTransport,PslFailure,PslValidator};
    $ips=[['ip'=>'93.184.216.34']]; $t=new PslTransport(null,function($host)use(&$ips){return $ips;});
    $url='https://psl.example.com/list';
    $refuse=function($code, $run) { try{$run();expect(false,'Must refuse '.$code);}catch(PslFailure $e){same($code,$e->safeCode,'Safe transport code');} };
    same('synthetic',$t->fetch($url,['If-None-Match: "v0"'])['body'],'Fake sender runs');
    foreach([CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_VERBOSE=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_RESOLVE=>['psl.example.com:443:93.184.216.34'],CURLOPT_HTTPHEADER=>['Accept: text/plain','If-None-Match: "v0"']]as$key=>$value){same($value,$options[$key],'Transport policy '.$key);}
    expect($options[CURLOPT_CONNECTTIMEOUT_MS]<=10000&&$options[CURLOPT_CONNECTTIMEOUT_MS]>0,'10s connect');
    expect($options[CURLOPT_TIMEOUT_MS]<=30000&&$options[CURLOPT_TIMEOUT_MS]>0,'30s total');
    foreach(['http://psl.example.com/list','https://127.0.0.1/list','https://[::1]/','https://user@psl.example.com/',
        'https://user:pass@psl.example.com/','https://psl.example.com:444/','https://psl.local/','https://psl.internal/',
        'https://psl.example.com/?token=x','https://psl.example.com/#fragment',"https://psl.example.com/\n",'https://psl.example.com/\\file']as$bad){
        $before=$executions; $refuse('source_invalid',fn()=>$t->fetch($bad,[])); same($before,$executions,'Unsafe URL zero exchanges');
    }
    foreach(['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','192.168.0.1','::1','fc00::1','::ffff:93.184.216.34']as$ip){
        $ips=[['ip'=>'93.184.216.34'],[str_contains($ip,':')?'ipv6':'ip'=>$ip]]; $before=$executions;
        $refuse('endpoint_unsafe',fn()=>$t->fetch($url,[]));same($before,$executions,'Mixed unsafe DNS zero exchanges');
    }
    $ips=[];$refuse('endpoint_unsafe',fn()=>$t->fetch($url,[]));
    $ips=[['ipv6'=>'2606:4700:4700::1111']];$t->fetch($url,[]);same(['psl.example.com:443:[2606:4700:4700::1111]'],$options[CURLOPT_RESOLVE],'IPv6 pin');
    $before=$executions;$status=302;$refuse('redirect_refused',fn()=>$t->fetch($url,[]));same($before+1,$executions,'No redirect or retry');
    $status=200;$errno=CURLE_OPERATION_TIMEDOUT;$before=$executions;$refuse('timeout',fn()=>$t->fetch($url,[]));same($before+1,$executions,'Timeout no retry');
    $errno=CURLE_RECV_ERROR;$refuse('transport_failed',fn()=>$t->fetch($url,[]));
    $errno=0;$chunks=[str_repeat('x',PslValidator::MAX_BYTES),'x'];$refuse('too_large',fn()=>$t->fetch($url,[]));
    same(0,$options[CURLOPT_WRITEFUNCTION]('fake','x'),'Streaming callback itself refuses excess bytes');
    $refuse('too_large',fn()=>(new PslTransport(fn()=>['status'=>200,'body'=>str_repeat('x',PslValidator::MAX_BYTES+1)]))->fetch($url,[]));
    $chunks=[''];$headers=[str_repeat('x',16385)];$refuse('transport_failed',fn()=>$t->fetch($url,[]));
    same(null,PslTransport::header("unsafe\r\nInjected: true"),'Header injection dropped');same(null,PslTransport::header(str_repeat('a',513)),'Header limit');
    echo "Public suffix fake cURL HTTPS, public IP pinning, no redirects/retries, deadlines and caps passed.\n";
}
