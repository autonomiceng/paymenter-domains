<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Raw input after the real middleware.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\RawPost;
require_once __DIR__.'/Support/raw-input.php';
foreach(['application/x-www-form-urlencoded','application/json']as$type){
    foreach(['  edge  ','','é雪',' + % %2B + ']as$value){
        $data=['transfer_code'=>$value,'records'=>[['name'=>'@','type'=>'TXT','address'=>$value,'mx_pref'=>''],
            ['service'=>'sip','protocol'=>'tcp','target'=>$value]],'confirmed'=>'1'];
        $raw=$type==='application/json'?json_encode($data,JSON_THROW_ON_ERROR):http_build_query($data,'','&',PHP_QUERY_RFC1738);
        $r=nativeNormalizeInput(Request::create('/domains/1/manage/dns','POST',$type==='application/json'?[]:$data,[],[],['CONTENT_TYPE'=>$type],$raw));
        $body=RawPost::body($r);
        same($value,$body['transfer_code'],'Exact code bytes survive normalization');
        same($value,$body['records'][0]['address'],'Exact DNS text survives normalization');
        same('',$body['records'][0]['mx_pref'],'Empty optional input remains empty');
        same($value,$body['records'][1]['target'],'SRV validation sees original target bytes');
    }
}
// RFC1738 distinguishes a literal plus from a space and decodes percent escapes only once.
$r=Request::create('/domains/1/transfer-code','POST',[],[],[],['CONTENT_TYPE'=>'application/x-www-form-urlencoded'],'transfer_code=+%2B%25%252B+');
same(' +%%2B ',RawPost::body($r)['transfer_code'],'Plus and percent form decoding is exact and single-pass');
$r=nativeNormalizeInput(Request::create('/core/unrelated','POST',['transfer_code'=>'  trim me  ','records'=>[['address'=>'  trim too  ']]]));
same('trim me',$r->input('transfer_code'),'Core field name has no global exemption');
same('trim too',$r->input('records.0.address'),'Core DNS-shaped input also has no exemption');
$r=Request::create('/domains/1/transfer-code?transfer_code=query-only','POST',[],[],[],['CONTENT_TYPE'=>'application/x-www-form-urlencoded'],'');
same([],RawPost::body($r),'Query fields never substitute for the raw POST body');
foreach([['application/json','{'],['application/json','[]'],['application/json','null'],['multipart/form-data','transfer_code=x'],
    ['application/x-www-form-urlencoded',str_repeat('x',1048577)],
    ['application/x-www-form-urlencoded',implode('&',array_fill(0,1001,'key=value'))]]as[$type,$raw]){
    try{RawPost::body(Request::create('/domains/1/manage/srv','POST',[],[],[],['CONTENT_TYPE'=>$type],$raw));expect(false,'Invalid/truncated body escaped');}
    catch(DomainException $e){same('Invalid form body.',$e->getMessage(),'Invalid input has a fixed secret-free refusal');}
}
// Declared oversize bodies are refused even when the actual body fits the limit.
foreach (['application/x-www-form-urlencoded', 'application/json'] as $type) {
    foreach (['1048577', '0001048577', '999999999999999999999999'] as $length) {
        foreach ([8, 2097152] as $bytes) {
            $raw = $type === 'application/json' ? '{"v":"'.str_repeat('x', $bytes-8).'"}' : 'v='.str_repeat('x', $bytes-2);
            $r = Request::create('/domains/1/manage/dns', 'POST', [], [], [], ['CONTENT_TYPE'=>$type, 'CONTENT_LENGTH'=>$length], $raw);
            try { RawPost::body($r); expect(false, 'Declared oversize body escaped'); }
            catch (DomainException $e) { same('Invalid form body.', $e->getMessage(), 'Declared size has a fixed refusal'); }
        }
    }
    $r = new class extends Request {
        public int $bodyCalls = 0;
        public function getContent(bool $asResource = false) {
            ++$this->bodyCalls;
            throw new RuntimeException('Oversize header must be refused before touching the body');
        }
    };
    $r->initialize([], [], [], [], [], ['REQUEST_METHOD'=>'POST', 'CONTENT_TYPE'=>$type, 'CONTENT_LENGTH'=>'1048577']);
    try { RawPost::body($r); expect(false, 'Declared oversize body escaped before reading'); }
    catch (DomainException $e) { same(0, $r->bodyCalls, 'Oversize header never calls either body API'); }
}
// Every body uses bounded stream reads, including understated and middleware-cached JSON.
foreach (['application/x-www-form-urlencoded', 'application/json'] as $type) {
    foreach ([null, '', 'unknown', '1048577oops', '-1', '1', '32', '1048576'] as $length) {
        foreach ([2097152, 1048576, 1048577] as $bytes) {
            $raw = $type === 'application/json' ? '{"value":"'.str_repeat('x', $bytes-12).'"}' : 'value='.str_repeat('x', $bytes-6);
            same($bytes, strlen($raw), 'Exact request size fixture');
            $r = new class extends Request {
                public array $bodyModes = [];
                public function getContent(bool $asResource = false) {
                    $this->bodyModes[] = $asResource;
                    return parent::getContent($asResource);
                }
            };
            $server = ['REQUEST_METHOD'=>'POST', 'CONTENT_TYPE'=>$type];
            if ($length !== null) { $server['CONTENT_LENGTH'] = $length; }
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $raw); rewind($stream);
            $cachedJson = $type === 'application/json' && $length === '1048576';
            $r->initialize([], [], [], [], [], $server, $cachedJson ? $raw : $stream);
            if ($cachedJson) { $r->json(); $r->bodyModes = []; }
            try {
                try {
                    $body = RawPost::body($r);
                    same(1048576, $bytes, 'Only bodies at or below the byte limit are accepted');
                    same($type === 'application/json' ? $bytes-12 : $bytes-6, strlen($body['value']), 'Boundary value remains complete');
                } catch (DomainException $e) {
                    expect($bytes > 1048576, 'Exact byte limit must remain valid');
                    same('Invalid form body.', $e->getMessage(), 'Oversize refusal remains secret-free');
                }
                if (!$cachedJson) {
                    same(min($bytes, 1048577), ftell($stream), 'Unknown or unusable length never removes the stream read bound');
                }
                same([true], $r->bodyModes, 'Every body uses the bounded resource API');
            } finally { fclose($stream); }
        }
    }
}
require __DIR__.'/Support/invoice-render-failures.php';
invoiceRenderFailures();
$nativeSuccessful=true;echo "Raw form/JSON whitespace, empty, multibyte, plus/percent and native trim isolation passed.\n";
