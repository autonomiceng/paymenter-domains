<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic registrar exchanges only.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
staffPanel();
$viewer=staffUser([Staff::VIEW]);
[$d,$driver]=$holding('nameserver-page-placeholder.com', NameserverEvidence::observed(['ns1.example.net','ns2.example.net'], 'enom_getdns', $clock->now()));
Auth::setUser($viewer);
$pages=static function($d, ?array $expected, string $why, bool $formsExpected=true) use($owners,$user,$clock): void {
    $summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',(int)$d->id,$clock->now()->getTimestamp());
    expect($summary!==null,$why.' customer summary exists');
    same($expected,($summary->nameservers['state']??null)==='observed'?$summary->nameservers['nameservers']:null,$why.' customer summary');
    $forms=$owners->present($d->fresh())->management;
    same($formsExpected,isset($forms['nameservers']),$why.' customer form availability');
    if ($formsExpected) { same($expected,$forms['nameservers']['current'],$why.' customer form'); }
    same($expected,DomainFacts::for($d->fresh())['Nameservers']['value'],$why.' staff');
};
$pages($d,['ns1.example.net','ns2.example.net'],'Registration readback before any observation');
$registered=$d->fresh()->observation;
foreach (['name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64),'nameservers'=>['invalid host']] as $field=>$value) {
    $saved=$registered; $saved['nameservers'][$field]=$value; $d->fresh()->update(['observation'=>$saved]);
    $pages($d,null,'Reject registration evidence with invalid '.$field);
}
$saved=$registered; unset($saved['nameservers']); $d->fresh()->update(['observation'=>$saved]);
$pages($d,null,'Missing registration evidence stays Missing');
$d->fresh()->update(['observation'=>$registered]);
same(null,$owners->present($d->fresh())->management['lock']['state'],'Registration does not invent customer lock');
same(null,DomainFacts::for($d->fresh())['Transfer lock']['value'],'Registration does not invent staff lock');
require __DIR__.'/Support/FakeDnsReader.php';
$profile=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create([
    'name'=>'Synthetic nameserver profile','nameservers'=>['ns1.example.net','ns2.example.net'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$viewer->id,'reason'=>'Synthetic page check']));
DB::transaction(fn()=>$profile->fill(['enabled'=>true,'revision'=>2,'reason'=>'Synthetic enabled profile'])->save());
$signals=new \Paymenter\Extensions\Servers\Domains\Services\ManagedPublicSignals(
    new \Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader(),true,fn()=>$clock->now()->getTimestamp(),$profile->id);
expect($signals->enqueue($d->id,$user->id),'Public nameserver check enrolled');
$publicCheck=static function(array $hosts)use($signals,$d,$user,$clock):void {
    DB::table(\Paymenter\Extensions\Servers\Domains\Services\ManagedPublicWorkSchema::TABLE)->where('domain_id',$d->id)->where('kind','delegation')->update(['due_at'=>$clock->now()->getTimestamp(),'backoff_until'=>null]);
    $ticket=$signals->begin($d->id,$user->id,'delegation',$clock->now()->getTimestamp());
    expect($ticket!==null,'Public nameserver check starts');
    $answer=\Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::rrset(new \Paymenter\Extensions\Servers\Domains\Dns\DnsQuery($d->domain,'NS'),array_map(
        fn($host)=>['host'=>$d->domain,'class'=>'IN','type'=>'NS','target'=>$host,'ttl'=>300],$hosts));
    expect($signals->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external',queries:[$answer]),$clock->now()->getTimestamp()+1),'Public nameserver check completes');
    $clock->instant=$clock->now()->modify('+2 seconds');
};
$op=$submit($d,$driver,'nameservers',['nameservers'=>['ns3.example.net','ns4.example.net']]);
same('succeeded',$management->run($op->id,$driver)->state,'Nameserver change has confirmed readback');
$pages($d,['ns3.example.net','ns4.example.net'],'Management readback before any observation');
$confirmed=$d->fresh()->observation;
foreach (['name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64)] as $field=>$value) {
    $saved=$confirmed; $saved['sections']['nameservers'][$field]=$value; $d->fresh()->update(['observation'=>$saved]);
    $pages($d,null,'Reject management evidence with mismatched '.$field);
}
$d->fresh()->update(['observation'=>$confirmed]);
$publicCheck(['ns3.example.net','ns4.example.net']);
$before=$op->fresh()->getAttributes(); $calls=$state->calls;
same('succeeded',$management->run($op->id,$driver)->state,'Repeated completion remains succeeded');
same([$before,$calls],[$op->fresh()->getAttributes(),$state->calls],'Repeated delivery preserves history and sends nothing');
Cache::flush();
$lock=$submit($d,$driver,'lock',['locked'=>false]);
same('succeeded',$management->run($lock->id,$driver)->state,'Lock has real registrar readback');
same(false,$owners->present($d->fresh())->management['lock']['state'],'Customer uses confirmed lock readback');
same('Off',DomainFacts::for($d->fresh())['Transfer lock']['value'],'Staff uses the same confirmed lock readback');
Cache::flush();
$next=$submit($d,$driver,'nameservers',['nameservers'=>['ns5.example.net','ns6.example.net']]);
same('succeeded',$management->run($next->id,$driver)->state,'A later nameserver change completes');
$pages($d,['ns5.example.net','ns6.example.net'],'Latest completed change wins');
Cache::flush(); $state->apply=false;
$uncertain=$submit($d,$driver,'nameservers',['nameservers'=>['ns7.example.net','ns8.example.net']]);
same('uncertain',$management->run($uncertain->id,$driver)->state,'Unconfirmed change stays uncertain');
$pages($d,['ns5.example.net','ns6.example.net'],'Unconfirmed change preserves confirmed nameservers',false);
$state->ns=['ns7.example.net','ns8.example.net'];
same('succeeded',$management->reconcile($uncertain->id,$driver)->state,'Later readback confirms without another write');
$pages($d,['ns7.example.net','ns8.example.net'],'Reconciliation projects the confirmed change');
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$budget=new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock);
$observer=new \Paymenter\Extensions\Servers\Domains\Services\DomainObservation($readBindings,$clock,$budget);
same('refreshed',$observer->run($d->id),'A later observation preserves the management projection');
$pages($d,['ns7.example.net','ns8.example.net'],'Observation keeps identity-checked management nameservers');
$clock->instant=$clock->now()->modify('+2 seconds');
$publicCheck(['ns9.example.net','ns10.example.net']);
$summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',(int)$d->id,$clock->now()->getTimestamp());
same(['ns9.example.net','ns10.example.net'],$summary->nameservers['nameservers'],'A newer public check supplies the summary');
same(['ns7.example.net','ns8.example.net'],$owners->present($d->fresh())->management['nameservers']['current'],'Customer form retains registrar readback');
same(['ns7.example.net','ns8.example.net'],DomainFacts::for($d->fresh())['Nameservers']['value'],'Staff retains registrar readback');
$observed=$d->fresh()->observation;
$confirmedLock=$observed['sections']['lock'];
$flags=static function(?bool $expected, string $why)use($d,$owners):void {
    same($expected,$owners->present($d->fresh())->management['lock']['state'],$why.' customer lock');
    same($expected===null?null:($expected?'On':'Off'),DomainFacts::for($d->fresh())['Transfer lock']['value'],$why.' staff lock');
    same($expected===null?null:false,$owners->present($d->fresh())->management['privacy']['state'],$why.' customer privacy');
    same($expected===null?null:'Off',DomainFacts::for($d->fresh())['WHOIS privacy']['value'],$why.' staff privacy');
};
$flags(false,'Matching lock section takes precedence over snapshot');
foreach (['name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64),'legacy'=>null] as $field=>$value) {
    $saved=$observed;
    if ($field==='legacy') { unset($saved['sections']['lock']['name'],$saved['sections']['lock']['generation'],$saved['sections']['lock']['account_fingerprint']); }
    else { $saved['sections']['lock'][$field]=$value; }
    $d->fresh()->update(['observation'=>$saved]);$flags(true,'Stale lock section falls back to current snapshot '.$field);
}
$d->fresh()->update(['observation'=>$observed]);$flags(false,'Matching false lock is preserved after stale sections');
unset($observed['sections']['lock'],$observed['sections']['privacy']);
$d->fresh()->update(['observation'=>$observed]);
$flags(true,'Current observation supplies lock');
foreach (['name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64)] as $field=>$value) {
    $saved=$observed; $saved['facts'][$field]=$value; $d->fresh()->update(['observation'=>$saved]);
    $flags(null,'Mismatched observation '.$field.' stays Missing');
}
$saved=$observed; unset($saved['facts']['generation'],$saved['facts']['account_fingerprint']);
$d->fresh()->update(['observation'=>$saved]);
$flags(null,'Legacy observation without identity stays Missing');
$observed['sections']['lock']=$confirmedLock;
$d->fresh()->update(['observation'=>$observed]);
while ($d->operations()->count()<=100) {
    $idle=$submit($d,$driver,'lock',['locked'=>false]);
    same('rejected',$management->retire($user,$idle->id)->state,'Unsent operation retires without registrar work');
}
same('refreshed',$observer->run($d->id),'Observation still runs after history exceeds the reconstruction bound');
same(['ns7.example.net','ns8.example.net'],DomainFacts::for($d->fresh())['Nameservers']['value'],'History overflow preserves confirmed staff nameservers');
same(['ns7.example.net','ns8.example.net'],\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::storedNameservers($d->fresh()),'History overflow preserves shared registrar evidence');
same([], $owners->present($d->fresh())->management,'History overflow keeps existing advisory form bound');
$overflowFlag=static function($holding,string $section,string $fact,?bool $expected,string $why)use($owners):void {
    same($expected,\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::storedFlag($holding->fresh(),$section,$fact),$why.' shared customer flag');
    $label=$section==='lock'?'Transfer lock':'WHOIS privacy';
    same($expected===null?null:($expected?'On':'Off'),DomainFacts::for($holding->fresh())[$label]['value'],$why.' staff flag');
    same([],$owners->present($holding->fresh())->management,$why.' retains advisory form bound');
};
$lockOverflow=$d->fresh()->observation;
foreach (['matched'=>null,'name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64),'legacy'=>null] as $field=>$value) {
    $saved=$lockOverflow;
    if ($field==='legacy') { unset($saved['sections']['lock']['name'],$saved['sections']['lock']['generation'],$saved['sections']['lock']['account_fingerprint']); }
    elseif ($field!=='matched') { $saved['sections']['lock'][$field]=$value; }
    $d->fresh()->update(['observation'=>$saved]);
    for ($cycle=0;$cycle<2;$cycle++) {
        $overflowFlag($d,'lock','locked',$field==='matched'?false:true,'Overflow lock '.$field.' cycle '.$cycle);
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
$clock->instant=new \DateTimeImmutable('2026-10-18T12:00:00Z');
[$requested,$driver]=$holding('requested-page-placeholder.com',NameserverEvidence::requested(['ns1.example.net','ns2.example.net'],'registration_request',$clock->now()));
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
    same($enabled?'On':'Off',DomainFacts::for($requested->fresh())['WHOIS privacy']['value'],'Staff reads confirmed Enom privacy');
}
Cache::flush();$toggle=$submit($requested,$driver,'privacy_enable');
same('succeeded',$management->run($toggle->id,$driver)->state,'Privacy overflow retains the latest real enable readback');
$privacyOverflow=$requested->fresh()->observation;
while ($requested->operations()->count()<=100) {
    $idle=$submit($requested,$driver,'lock',['locked'=>false]);
    same('rejected',$management->retire($user,$idle->id)->state,'Privacy history uses unsent retired operations');
}
foreach (['matched'=>null,'name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64),'legacy'=>null] as $field=>$value) {
    $saved=$privacyOverflow;
    if ($field==='legacy') { unset($saved['sections']['privacy']['name'],$saved['sections']['privacy']['generation'],$saved['sections']['privacy']['account_fingerprint']); }
    elseif ($field!=='matched') { $saved['sections']['privacy'][$field]=$value; }
    $requested->fresh()->update(['observation'=>$saved]);
    for ($cycle=0;$cycle<2;$cycle++) {
        $overflowFlag($requested,'privacy','privacy',$field==='matched'?true:false,'Overflow privacy '.$field.' cycle '.$cycle);
        same('refreshed',$observer->run($requested->id),'Repeated overflow privacy sync '.$field);
        $overflowFlag($requested,'privacy','privacy',$field==='matched'?true:false,'After overflow privacy sync '.$field.' cycle '.$cycle);
    }
}
echo "Nameserver customer and staff registration/management flows passed\n";
$nativeSuccessful=true;
