<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native nameserver page evidence.
declare(strict_types=1);
require __DIR__.'/Support/nameserver-pages.php';
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
[$d,$driver]=$holding('nameserver-page-placeholder.com', NameserverEvidence::observed(['ns1.example.net','ns2.example.net'], 'enom_getdns', $clock->now()));
Auth::setUser($viewer);
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
// This page fixture deliberately chooses its comparison template; a global default is not DNS intent.
DB::transaction(function()use($d,$profile):void {
    $config=\Paymenter\Extensions\Servers\Domains\Models\ManagedPublicConfig::findOrFail($d->id);
    $config->fill(['profile_id'=>$profile->id,'revision'=>$config->revision+1])->save();
});
expect($signals->enqueue($d->id,$user->id),'Configured public nameserver check enrolled');
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
$nativeSuccessful=true;echo "Nameserver page flows passed\n";
