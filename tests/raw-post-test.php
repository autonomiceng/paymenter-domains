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
$nativeSuccessful=true;echo "Raw form/JSON whitespace, empty, multibyte, plus/percent and native trim isolation passed.\n";
