<?php
// Synthetic offline management fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/transfer-completion-namesilo-fixture.php';
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement, WriteFeatures};
Queue::fake(); $management = new DomainManagement($readBindings, $clock, new WriteFeatures(management:true), true);
$ms = (object) ['calls'=>[], 'ns'=>['ns1.provider.net','ns2.provider.net'], 'locked'=>true, 'privacy'=>false, 'renew'=>false,
    'ids'=>[], 'profiles'=>[], 'next'=>900000, 'apply'=>true, 'fault'=>'', 'during'=>null, 'route'=>'api'];
$managementExchange = static function ($origin, $cmd, $p, $timeout) use ($ms): string {
    same(0, DB::transactionLevel(), 'Management I/O outside locks');
    same('https://sandbox.namesilo.com/'.$ms->route, $origin, 'Fixed origin and caller route');
    expect($timeout > 0 && $timeout <= 25000, 'Bounded wire timeout'); $ms->calls[] = [$cmd,$p];
    if ($ms->during) { $hook=$ms->during; $ms->during=null; $hook($cmd); }
    if ($ms->fault === $cmd) { return '{}'; }
    if ($ms->fault === 'large') { return str_repeat('x',1048577); }
    $reply = ['code'=>300,'detail'=>'synthetic'];
    if ($cmd === 'getDomainInfo') {
        $reply += ['created'=>'2020-01-01','expires'=>'2028-01-01','status'=>$ms->fault==='owner'?'Pending':'Active',
            'locked'=>$ms->locked?'Yes':'No', 'private'=>$ms->privacy?'Yes':'No', 'auto_renew'=>$ms->renew?'Yes':'No',
            'nameservers'=>array_map(fn($ns)=>['nameserver'=>$ns],$ms->ns), 'contact_ids'=>$ms->ids];
    } elseif ($cmd === 'contactList') {
        $reply['contact'] = ($ms->profiles[$p['contact_id']] ?? []) + ['contact_id'=>$p['contact_id'],'default_profile'=>$ms->fault==='default'?'1':'0'];
        if ($ms->fault === 'mismatch') { $reply['contact']['email']='different@example.com'; }
    } elseif ($cmd === 'contactAdd') {
        $id = (string) ++$ms->next;
        $ms->profiles[$id] = array_combine(['first_name','last_name','company','address','address2','city','state','zip','country','email','phone'],array_values($p));
        $reply['contact_id']=$id;
    } elseif ($ms->apply) {
        match ($cmd) {
            'changeNameServers' => $ms->ns=array_values(array_filter($p,fn($k)=>preg_match('/^ns[0-9]+$/D',$k),ARRAY_FILTER_USE_KEY)),
            'domainLock' => $ms->locked=true, 'domainUnlock' => $ms->locked=false,
            'addPrivacy' => $ms->privacy=true, 'removePrivacy' => $ms->privacy=false, 'removeAutoRenewal' => $ms->renew=false,
            'contactDomainAssociate' => $ms->ids=array_replace($ms->ids,array_diff_key($p,['domain'=>true])),
            'retrieveAuthCode' => null, default => throw new LogicException('Unexpected management write'),
        };
    }
    if ($ms->fault === 'lost' && !in_array($cmd,['getDomainInfo','contactList'],true)) { throw new RuntimeException('Synthetic lost acknowledgement'); }
    return json_encode(['request'=>['operation'=>$cmd],'reply'=>$reply]);
};
$holding = static function ($label, bool $complete=true) use ($prepare,$reconcile,&$binding,$ms,$managementExchange,$clock,$now) {
    $clock->instant=$now; Carbon\Carbon::setTestNow($now); Carbon\CarbonImmutable::setTestNow($now);
    $original=$prepare('management-'.strtolower(str_replace('_','-',$label)));
    if ($complete) { same('succeeded',$reconcile->run($original->id)->state,'Native transfer completion establishes holding'); }
    Carbon\Carbon::setTestNow($clock->now()); Carbon\CarbonImmutable::setTestNow($clock->now());
    $ms->calls=[]; $ms->fault=''; $ms->apply=true; $ms->during=null; $ms->route='api';
    $ms->ns=['ns1.provider.net','ns2.provider.net']; $ms->locked=true; $ms->privacy=false; $ms->renew=false; $ms->ids=[];
    Cache::flush(); return [$original->domain()->firstOrFail(), new Management($binding,$managementExchange), $original->fresh()];
};
$seq=0; $key=static function () use (&$seq) { return hash('sha256','namesilo-management-'.++$seq); };
$submit=static fn($d,$driver,$action,$payload=[])=>$management->request($user,$d->id,$action,$payload,$key(),$driver);
$writes=static fn()=>array_values(array_filter($ms->calls,fn($row)=>!in_array($row[0],['getDomainInfo','contactList'],true)));
