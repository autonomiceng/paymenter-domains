<?php
// Synthetic functions shadow only this protocol namespace; no sockets.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
function hrtime(bool $asNumber=false): mixed { if($hook=$GLOBALS['enomWire']['before']??null){$GLOBALS['enomWire']['before']=null;$hook();}return $GLOBALS['enomWire']['nanoseconds']??0; }
function curl_init(): object|false { $GLOBALS['enomWire']['inits']++;return $GLOBALS['enomWire']['initFail']?false:new \stdClass; }
function curl_setopt_array($h,array $options): bool { $GLOBALS['enomWire']['options']=$options;return !$GLOBALS['enomWire']['configFail']; }
function curl_exec($h): bool
{
    $fake=&$GLOBALS['enomWire'];parse_str($fake['options'][CURLOPT_POSTFIELDS],$form);$fake['forms'][]=$form;
    if ($fake['during']) { ($fake['during'])($form); }
    if ($fake['timeout']) { throw new \RuntimeException('Synthetic secret failure: '.$fake['options'][CURLOPT_POSTFIELDS]); }
    $chunks=$fake['reply']?[(string)($fake['reply'])($form)]:$fake['chunks'];
    foreach ($chunks as $chunk) { if (($fake['options'][CURLOPT_WRITEFUNCTION])($h,$chunk)!==strlen($chunk)) { return false; } }
    return !$fake['execFail'];
}
function curl_getinfo($h,int $option): int { return $GLOBALS['enomWire']['status']; }
function curl_close($h): void { $GLOBALS['enomWire']['closed']++; }
function resetWire(array $extra=[]): void
{
    $GLOBALS['enomWire']=$extra+['inits'=>0,'closed'=>0,'options'=>[],'forms'=>[],'initFail'=>false,'configFail'=>false,
        'execFail'=>false,'timeout'=>false,'status'=>200,'chunks'=>['xml'],'reply'=>null,'during'=>null,'nanoseconds'=>0];
}
// Compare the existing read wire's configuration with the new authenticated protocol wire.
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;
function curl_init(): object|false {return \Paymenter\Extensions\Servers\Domains\Registrars\Enom\curl_init();}
function curl_setopt_array($h,array $o): bool {return \Paymenter\Extensions\Servers\Domains\Registrars\Enom\curl_setopt_array($h,$o);}
function curl_exec($h): bool {return \Paymenter\Extensions\Servers\Domains\Registrars\Enom\curl_exec($h);}
function curl_getinfo($h,int $o): int {return \Paymenter\Extensions\Servers\Domains\Registrars\Enom\curl_getinfo($h,$o);}
function curl_close($h): void {\Paymenter\Extensions\Servers\Domains\Registrars\Enom\curl_close($h);}
