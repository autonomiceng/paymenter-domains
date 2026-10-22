<?php
// Synthetic, socket-free OTE evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($handle,array $options): bool {$GLOBALS['enrollmentOptions']=$options;return true;}
function curl_getinfo($handle,int $option): int {return 200;}
function curl_close($handle): void {}
function curl_exec($handle): bool
{
    $o=$GLOBALS['enrollmentOptions'];$GLOBALS['enrollmentCalls']++;
    \same(0,\Illuminate\Support\Facades\DB::transactionLevel(),'OTE key check outside locks');
    $url=parse_url($o[CURLOPT_URL]);parse_str($url['query'],$query);
    \same('https',$url['scheme'],'HTTPS only');\same('ote.namesilo.com',$url['host'],'Exact OTE host');
    $context=$GLOBALS['enrollmentExpectedContext']??'api';
    \same('/'.$context.'/getAccountBalance',$url['path'],'One reviewed read-only endpoint for the expected request context');
    \same(['version','type','key'],array_keys($query),'No identity/contact/order substitute on wire');
    \same(false,$o[CURLOPT_FOLLOWLOCATION],'Redirects disabled');\same('',$o[CURLOPT_PROXY],'Proxy disabled');
    \same($GLOBALS['enrollmentExpectedTimeout']??10000,$o[CURLOPT_TIMEOUT_MS],'Expected bounded request deadline');
    if(isset($GLOBALS['enrollmentDuringRead'])){($GLOBALS['enrollmentDuringRead'])();}
    $mode=$GLOBALS['enrollmentMode']??'ok';
    if($mode==='throw'){throw new \RuntimeException($o[CURLOPT_URL].' sandbox@example.test 9876.54');}
    $reply=$query['key']==='wrong-key'?['code'=>110,'detail'=>'Wrong key']:
        ['code'=>300,'detail'=>'success','balance'=>'9876.54','userId'=>'123','ownerUserId'=>'456','contact_id'=>'789','order_number'=>'999'];
    if($mode==='missing_balance'){unset($reply['balance']);}
    if($mode==='bad_balance'){$reply['balance']='NaN';}
    if($mode==='null_balance'){$reply['balance']=null;}
    $body=$mode==='html'?'<html>9876.54</html>':json_encode(['request'=>['operation'=>$mode==='wrong_operation'?'orderDetails':'getAccountBalance'],'reply'=>$reply]);
    $GLOBALS['enrollmentLastRead']=['path'=>$url['path'],'mode'=>$mode,'key_valid'=>$query['key']==='synthetic-ote-key','body'=>$body];
    return ($o[CURLOPT_WRITEFUNCTION])($handle,$body)===strlen($body);
}
