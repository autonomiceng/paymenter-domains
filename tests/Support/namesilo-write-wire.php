<?php
// Namespace cURL fakes: no sockets, synthetic replies only.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\LiveReservations;
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($h,array $options): bool {$GLOBALS['nsOptions']=array_replace($GLOBALS['nsOptions']??[],$options);return true;}
function curl_exec($h): bool {
    $url=$GLOBALS['nsOptions'][CURLOPT_URL];parse_str(parse_url($url,PHP_URL_QUERY),$p);unset($p['key'],$p['version'],$p['type']);
    $cmd=basename(parse_url($url,PHP_URL_PATH));$GLOBALS['nsPhysical'][]=$cmd;
    if(AdmittedTransport::feature($cmd)!=='catalogue'){
        $op=Operation::findOrFail($GLOBALS['nsOperation']);same('sealed',$op->attempt['seal'],'Durable engine seal precedes physical send');
        expect(isset($op->attempt['dispatch_attempted_at']),'Dispatch marker precedes physical send');
        same(false,$GLOBALS['nsTransport']->reservation->unconsumed((int)$op->id),'Exact grant consumed at physical handoff');
        if($op->funding_basis==='paid_invoice'){expect(isset($op->funding['seal']),'Native funding sealed before paid send');}
        same('https://www.namesilo.com/apibatch/'.$cmd,strtok($url,'?'),'Fixed Live batch mutation route');
    }
    $body=($GLOBALS['nsReply'])($cmd,$p);
    return ($GLOBALS['nsOptions'][CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
function curl_getinfo($h,int $option): int {return 200;}
function curl_close($h): void {}
function nsWriteTransport($binding,$clock): AdmittedTransport {
    app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
    $a=Account::where('server_id',$binding->serverId)->sole();$GLOBALS['nsAccountKey']='domains.namesilo_live.accounts.'.$a->stable_identity;
    config(['domains.namesilo_live.site'=>rtrim((string)config('app.url'),'/'),$GLOBALS['nsAccountKey']=>[
        'fingerprint'=>$binding->fingerprint,'daily_call_cap'=>1000,'daily_spend_limit_usd'=>'1000.00','currency'=>'USD','balance_funding'=>true],
        'domains.namesilo_live.catalogue'=>true,'domains.namesilo_live.renewal'=>true,'domains.namesilo_live.management'=>true,'domains.provider_send_guard'=>null]);
    $GLOBALS['nsPhysical']=[];
    return $GLOBALS['nsTransport']=new AdmittedTransport($binding,static fn(bool $write)=>['api_key'=>'placeholder-api-key']);
}
function nsUsage($binding): array {return (new LiveReservations(new NameSiloLiveReservationPolicy))->usage($binding);}
