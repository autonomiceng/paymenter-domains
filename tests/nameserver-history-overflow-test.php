<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native nameserver page evidence.
declare(strict_types=1);
require __DIR__.'/Support/nameserver-pages.php';
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
[$d,$driver]=$holding('nameserver-overflow-placeholder.com',NameserverEvidence::observed(['ns1.example.net','ns2.example.net'],'enom_getdns',$clock->now()));
Auth::setUser($viewer);
$change=$submit($d,$driver,'nameservers',['nameservers'=>['ns7.example.net','ns8.example.net']]);
same('succeeded',$management->run($change->id,$driver)->state,'Real confirmed nameservers seed overflow');
Cache::flush();$lock=$submit($d,$driver,'lock',['locked'=>false]);
same('succeeded',$management->run($lock->id,$driver)->state,'Real confirmed lock seeds overflow');
same('refreshed',$observer->run($d->id),'Current observation seeds overflow');
$confirmedLock=$d->fresh()->observation['sections']['lock'];
$history($d,$driver,'Unsent operation retires without registrar work');
same('refreshed',$observer->run($d->id),'Observation still runs after history exceeds the reconstruction bound');
same(['ns7.example.net','ns8.example.net'],DomainFacts::for($d->fresh())['Nameservers']['value'],'History overflow preserves confirmed staff nameservers');
same(['ns7.example.net','ns8.example.net'],\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::storedNameservers($d->fresh()),'History overflow preserves shared registrar evidence');
same([],array_filter($owners->present($d->fresh())->management,fn($section)=>$section['actions']),'History overflow keeps every action blocked');
$lockOverflow=$d->fresh()->observation;
foreach (['matched'=>null,'name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64),'legacy'=>null] as $field=>$value) {
    $saved=$lockOverflow;
    if ($field==='legacy') { unset($saved['sections']['lock']['name'],$saved['sections']['lock']['generation'],$saved['sections']['lock']['account_fingerprint']); }
    elseif ($field!=='matched') { $saved['sections']['lock'][$field]=$value; }
    $d->fresh()->update(['observation'=>$saved]);
    for ($cycle=0;$cycle<2;$cycle++) {
        $overflowFlag($d,'lock','locked',$field==='matched'?false:true,'Overflow lock '.$field.' cycle '.$cycle);
        $clock->instant=$clock->now()->modify('+61 seconds'); // Separate scheduled turns share a minute budget.
        same('refreshed',$observer->run($d->id),'Repeated overflow lock sync '.$field);
        $overflowFlag($d,'lock','locked',$field==='matched'?false:true,'After overflow lock sync '.$field.' cycle '.$cycle);
    }
}
$d->fresh()->update(['observation'=>$lockOverflow]);

// Model the previous release's section by removing only the new identity fields from the real readback.
$legacy=$d->fresh()->observation;
unset($legacy['sections']['nameservers']['name'],$legacy['sections']['nameservers']['generation'],$legacy['sections']['nameservers']['account_fingerprint']);
$d->fresh()->update(['observation'=>$legacy]);
same(null,DomainFacts::for($d->fresh())['Nameservers']['value'],'Legacy section fails closed before sync');
$clock->instant=$clock->now()->modify('+1 day');
same('refreshed',$observer->run($d->id),'Legacy overflow observation completes');
$pages($d,null,'Legacy overflow cannot resurrect registration nameservers',false);
same('refreshed',$observer->run($d->id),'Repeated legacy overflow observation completes');
$pages($d,null,'Repeated overflow stays Missing',false);
$missing=$d->fresh()->observation; unset($missing['sections']['nameservers']);
$d->fresh()->update(['observation'=>$missing]);
same('refreshed',$observer->run($d->id),'Overflow without a saved section completes');
$pages($d,null,'Incomplete history cannot restore acquisition evidence',false);
$nativeSuccessful=true;echo "Nameserver history overflow passed\n";
