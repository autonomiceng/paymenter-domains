<?php
// Synthetic installed identity evidence only; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
function curl_init(): object { return new \stdClass; }
function curl_setopt_array($handle, array $options): bool { $GLOBALS['identityOptions']=$options; return true; }
function curl_getinfo($handle, int $option): int { return 200; }
function curl_close($handle): void {}
function identityXml(string $payload, string $count='0', string $done='true'): string
{
    return '<interface-response><Reseller>1</Reseller><Command>GETBALANCE</Command><APIType>API</APIType><Language>eng</Language><ErrCount>'.$count.'</ErrCount><Done>'.$done.'</Done>'.$payload.'</interface-response>';
}
function curl_exec($handle): bool
{
    $o=$GLOBALS['identityOptions'];parse_str($o[CURLOPT_POSTFIELDS],$form);$GLOBALS['identityCalls'][]=$form;
    \same(0,\Illuminate\Support\Facades\DB::transactionLevel(),'Identity/balance read outside locks');
    if (isset($GLOBALS['catalogueWire'])) { return ($GLOBALS['catalogueWire'])($handle, $o, $form); }
    \same('GetBalance',$form['Command'],'Account-only allowlist');\same('XML',$form['ResponseType'],'XML response');
    \same(['Command','UID','PW','ResponseType'],array_keys($form),'No domain/contact/override parameters');
    \same(false,$o[CURLOPT_FOLLOWLOCATION],'No redirects');\same(0,$o[CURLOPT_MAXREDIRS],'No redirects limit');
    \same('',$o[CURLOPT_PROXY],'No inherited proxy');\expect($o[CURLOPT_TIMEOUT_MS]<=10000,'Bounded identity/probe deadline');
    $live=$o[CURLOPT_URL]==='https://reseller.enom.com/interface.asp';
    if(!$live){\same('https://resellertest.enom.com/interface.asp',$o[CURLOPT_URL],'Exact TEST host');}
    $mode=$GLOBALS['identityMode']??'credentials';
    if($mode==='timeout'){throw new \RuntimeException('secret-uid secret-token 9876.54');}
    $body=match($mode){
        'html'=>'<html><body>9876.54</body></html>', 'partial'=>'<interface-response>',
        'done'=>identityXml('<AvailableBalance>9876.54</AvailableBalance>','0','false'),
        'errors'=>identityXml('<AvailableBalance>9876.54</AvailableBalance>','1'),
        'missing_balance'=>identityXml('<DomainCount>0</DomainCount>'),
        'bad_balance'=>identityXml('<AvailableBalance>NaN</AvailableBalance>'),
        'empty_account'=>identityXml('<Balance>0.00</Balance><AvailableBalance>0.00</AvailableBalance><DomainCount>0</DomainCount>'),
        default=>!$live&&in_array($form['UID'],['secret-uid','SECRET-UID','other-uid'],true)&&in_array($form['PW'],['secret-token','rotated-token'],true)
            ?identityXml('<Balance>9876.54</Balance><AvailableBalance>9876.54</AvailableBalance><DomainCount>0</DomainCount><Site>eNom</Site>')
            :identityXml('<errors><Err1>Bad User name or Password</Err1></errors><responses><response><ResponseNumber>304155</ResponseNumber><ResponseString>Validation error; invalid ; loginid</ResponseString></response></responses>','1'),
    };
    $written=($o[CURLOPT_WRITEFUNCTION])($handle,$body);
    if($written===strlen($body)&&isset($GLOBALS['enomTestCallClock'])){
        $GLOBALS['enomTestCallClock']->instant=$GLOBALS['enomTestCallClock']->now()->modify('+61 seconds');
    }
    return $written===strlen($body);
}
