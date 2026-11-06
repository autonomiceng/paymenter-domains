<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native nameserver page evidence.
declare(strict_types=1);
require __DIR__.'/Support/nameserver-pages.php';
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
$afterLockChecks=$clock->now()->modify('+61 seconds');
[$requested,$driver]=$holding('requested-page-placeholder.com',NameserverEvidence::requested(['ns1.example.net','ns2.example.net'],'registration_request',$clock->now()));
$clock->instant=$afterLockChecks;
Auth::setUser($viewer);
$pages($requested,null,'Requested registration evidence stays unconfirmed');
$summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',(int)$requested->id,$clock->now()->getTimestamp());
same('requested',$summary->nameservers['state'],'Requested registration evidence keeps its summary label');
same('refreshed',$observer->run($requested->id),'Privacy fixture has its own current snapshot');
$state->privacy=true; $state->enabled=false;
foreach (['privacy_enable'=>true,'privacy_disable'=>false] as $action=>$enabled) {
    Cache::flush(); $toggle=$submit($requested,$driver,$action);
    same('succeeded',$management->run($toggle->id,$driver)->state,'Enom privacy toggle has confirmed readback');
    same($enabled,$owners->present($requested->fresh())->management['privacy']['state'],'Customer reads confirmed Enom privacy');
    same($enabled?'On':null,DomainFacts::for($requested->fresh())['WHOIS privacy']['value']??null,'Staff sees Enom privacy only while it is on');
}
Cache::flush();$toggle=$submit($requested,$driver,'privacy_enable');
same('succeeded',$management->run($toggle->id,$driver)->state,'Privacy overflow retains the latest real enable readback');
$privacyOverflow=$requested->fresh()->observation;
$history($requested,$driver,'Privacy history uses unsent retired operations');
foreach (['matched'=>null,'name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64),'legacy'=>null] as $field=>$value) {
    $saved=$privacyOverflow;
    if ($field==='legacy') { unset($saved['sections']['privacy']['name'],$saved['sections']['privacy']['generation'],$saved['sections']['privacy']['account_fingerprint']); }
    elseif ($field!=='matched') { $saved['sections']['privacy'][$field]=$value; }
    $requested->fresh()->update(['observation'=>$saved]);
    for ($cycle=0;$cycle<2;$cycle++) {
        $overflowFlag($requested,'privacy','privacy',$field==='matched'?true:false,'Overflow privacy '.$field.' cycle '.$cycle);
        $clock->instant=$clock->now()->modify('+61 seconds');
        same('refreshed',$observer->run($requested->id),'Repeated overflow privacy sync '.$field);
        $overflowFlag($requested,'privacy','privacy',$field==='matched'?true:false,'After overflow privacy sync '.$field.' cycle '.$cycle);
    }
}
$nativeSuccessful=true;echo "Privacy history overflow passed\n";
