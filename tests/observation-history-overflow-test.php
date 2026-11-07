<?php
// Full native observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/observation-records.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,ObservationWork,OwnerRecords,ReadDeferred};
// History alone can overflow while partial child-host history stays below its separate cap.
$observation->years=1;
[$d,$driver]=$holding('history-placeholder.com');
// Retain the original independent DNS-editor gate when arranging this history-only fixture.
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
// The full matrix had already arranged this positive GetDNS reply before entering its history-only case.
$observation->nameservers=['dns1.name-services.com','dns2.name-services.com'];
$state->dns=[['name'=>'@','type'=>'TXT','address'=>'old','mx_pref'=>10]];
$state->srv=[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>443,'target'=>'sip.example.com.']];
$state->ds=[['algorithm'=>13,'digest_type'=>2,'key_tag'=>12,'digest'=>str_repeat('A',64)]];
same('refreshed',$observer->run($d->id,readRecords:true),'History fixture starts with complete inventory');
$families=$d->fresh()->observation['records']['families'];
$families['glue']=['state'=>'partial','records'=>['ns1.history-placeholder.com'=>'8.8.8.8']];
ObservationRecords::save($d->fresh(),$families,$clock->now());
foreach ([
    ['dns',['records'=>[['name'=>'@','type'=>'TXT','address'=>'new','mx_pref'=>10]],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest($state->dns)]],
    ['srv',['records'=>[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>8443,'target'=>'sip.example.com.']],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest($state->srv,'srv')]],
    ['ds_add',['ds'=>['algorithm'=>13,'digest_type'=>2,'key_tag'=>13,'digest'=>str_repeat('B',64)]]],
    ['nameservers',['nameservers'=>['ns3.example.net','ns4.example.net']]],
    ['lock',['locked'=>false]],
] as [$action,$payload]) {
    Cache::flush();$changed=$submit($d,$driver,$action,$payload);
    same('succeeded',$management->run($changed->id,$driver)->state,'Newer confirmed history '.$action);
}
$state->privacy=true;$state->enabled=true;Cache::flush();$changed=$submit($d,$driver,'privacy_disable');
same('succeeded',$management->run($changed->id,$driver)->state,'History fixture has a real privacy readback');
$preserved=$d->fresh()->observation['sections'];
$raw=$changed->fresh()->getRawOriginal();unset($raw['id']);
// Unsent rejected history is resolved without borrowing another operation's readback.
$raw['state']='rejected';$raw['attempt']=null;
for ($i=$d->operations()->count();$i<100;$i++) {
    $raw['operation_key']=hash('sha256','history-bound-'.$i);DB::table('domains_operations')->insert($raw);
}
same(100,$d->operations()->count(),'Exact reconstruction boundary');
$known=OwnerRecords::forDomain($d->fresh());
same('new',$known['dns'][0]['address'],'At100 newer DNS replaces inventory');
same(8443,$known['srv'][0]['port'],'At100 newer SRV replaces inventory');
same(2,count($known['ds']),'At100 newer DS overlays inventory');
$srvPayload=['records'=>$known['srv'],'record_sources'=>['0'],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest($known['srv'],'srv')];
same($known['srv'],OwnerRecords::restoreSrv($d->fresh(),$srvPayload)['records'],'At100 SRV source indexes remain usable');
$raw['operation_key']=hash('sha256','history-bound-101');DB::table('domains_operations')->insert($raw);
same(101,$d->operations()->count(),'History overflow is independent of the glue cap');
$inventory=$d->fresh()->observation['records'];$domainIo=count($observation->calls);$recordIo=count($state->calls);
foreach ([1,2] as $cycle) {
    $sections=ObservationProjection::sections($d->fresh());
    foreach (['dns','srv','ds'] as $kind) {
        expect(!isset($sections[$kind]),'History overflow omits stale '.$kind.' projection');
        expect(!isset(OwnerRecords::forDomain($d->fresh())[$kind]),'History overflow hides owner '.$kind.' list');
    }
    try { OwnerRecords::restoreSrv($d->fresh(),$srvPayload);expect(false,'Overflow must refuse stale SRV sources'); }
    catch (\Paymenter\Extensions\Servers\Domains\Services\OwnerFormValidation) {}
    foreach (['nameservers','lock','privacy'] as $kind) { same($preserved[$kind],$sections[$kind],'Overflow keeps matched '.$kind); }
    same(['ns1.history-placeholder.com'=>'8.8.8.8'],$sections['known_glue'],'Overflow retains explicitly partial glue');
    foreach (['name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64),'legacy'=>null] as $field=>$value) {
        $copy=clone $d->fresh();$saved=$copy->observation;
        foreach (['nameservers','lock','privacy'] as $kind) {
            if ($field==='legacy') { unset($saved['sections'][$kind]['name'],$saved['sections'][$kind]['generation'],$saved['sections'][$kind]['account_fingerprint']); }
            else { $saved['sections'][$kind][$field]=$value; }
        }
        $copy->observation=$saved;$rejected=ObservationProjection::sections($copy);
        same([],$rejected['nameservers'],'Overflow refuses '.$field.' nameservers');
        foreach (['lock','privacy'] as $kind) { expect(!isset($rejected[$kind]),'Overflow refuses '.$field.' '.$kind); }
    }
    $clock->instant=$clock->now()->modify('+1 day');
    same('refreshed',$observer->run($d->id,readRecords:false),'Core-only sync still refreshes facts during overflow cycle '.$cycle);
    same($inventory,$d->fresh()->observation['records'],'Overflow cannot advance inventory cycle '.$cycle);
    expect(!ObservationRecords::held($d->fresh()),'Core-only sync does not manufacture a history hold');
    $preserved=$d->fresh()->observation['sections'];
    same($observation->nameservers,$preserved['nameservers']['facts']['nameservers'],'Daily read refreshes current nameservers during overflow');
}
same($recordIo,count($state->calls),'Core-only reads do not discover records during history overflow');
expect(count($observation->calls)>$domainIo,'Daily domain reads continue across overflow cycles');

// Overflow keeps fresh per-host evidence, and later confirmed deletion wins over inventory/preflight.
$directHost='ns-direct.'.$d->domain;$overflowHost='ns-new.'.$d->domain;$inventoryHost='ns1.'.$d->domain;
$state->glue[$directHost]='1.1.1.1';
\Paymenter\Extensions\Servers\Domains\Services\GlueRecords::prepared($d->fresh(),['glue'=>['host'=>$directHost,'ip'=>'1.1.1.1']],$clock->now());
$observed=['fresh direct read'=>ObservationProjection::sections($d->fresh())['known_glue'][$directHost]??null];
require_once __DIR__.'/Support/FakeDnsReader.php';
$dns=new \Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader();
$query=new \Paymenter\Extensions\Servers\Domains\Dns\DnsQuery($directHost,'A');
$dns->answers[$query->key()]=\Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::negative($query);
$drift=\Paymenter\Extensions\Servers\Domains\Services\GlueDrift::check($d->fresh(),$dns,new \Paymenter\Extensions\Servers\Domains\Dns\DnsContext(),0,$clock->now()->getTimestamp(),900);
$observed['fresh host drift check']=$drift['hosts'][$directHost]['drift']??null;
foreach (['glue_add'=>['host'=>$overflowHost,'ip'=>'1.1.1.1'],'glue_delete'=>['host'=>$overflowHost]] as $action=>$payload) {
    Cache::flush();$changed=$submit($d,$driver,$action,$payload);
    same('succeeded',$management->run($changed->id,$driver)->state,'Overflow child-host '.$action.' confirmed');
    $observed[$action]=ObservationProjection::sections($d->fresh())['known_glue'][$overflowHost]??null;
}
$state->glue[$inventoryHost]='8.8.8.8';
Cache::flush();$changed=$submit($d,$driver,'glue_delete',['host'=>$inventoryHost]);
same('succeeded',$management->run($changed->id,$driver)->state,'Overflow inventory-host delete confirmed');
$observed['inventory host delete']=ObservationProjection::sections($d->fresh())['known_glue'][$inventoryHost]??null;
// Readbacks must remain current after the operation itself falls out of the bounded window.
for ($i=0;$i<101;$i++) { $raw['operation_key']=hash('sha256','history-after-delete-'.$i);DB::table('domains_operations')->insert($raw); }
$truncated=ObservationProjection::sections($d->fresh());
$observed['aged new host delete']=$truncated['known_glue'][$overflowHost]??null;
$observed['aged inventory host delete']=$truncated['known_glue'][$inventoryHost]??null;
$expected=['fresh direct read'=>'1.1.1.1','fresh host drift check'=>true,'glue_add'=>'1.1.1.1','glue_delete'=>null,
    'inventory host delete'=>null,'aged new host delete'=>null,'aged inventory host delete'=>null];
foreach ($expected as $case=>$value) { if ($value!==$observed[$case]) { fwrite(STDERR,'Regression: '.$case.' expected '.json_encode($value).' got '.json_encode($observed[$case])."\n"); } }
same('1.1.1.1',$observed['fresh direct read'],'Fresh persisted glue survives truncated history');
same($expected,$observed,'Fresh direct/add evidence stays visible and drift-checked; newer deletes never resurrect after overflow');
foreach (['dns','srv','ds'] as $kind) { expect(!isset($truncated[$kind]),'Deleted glue does not unlock truncated '.$kind.' list'); }


// A fresh read safely replaces stale whole-list inventory despite the lifetime operation count.
$recordIo=count($state->calls);$clock->instant=$clock->now()->modify('+61 seconds');
ObservationRecords::hold($d->fresh(),'history_limit',$clock->now());
same('refreshed',$observer->run($d->id,readRecords:true),'Sync recovers a legacy history-limit hold');
expect(count($state->calls)>$recordIo,'More than100 operations no longer refuse record discovery');
expect(!ObservationRecords::held($d->fresh()),'Successful discovery clears the old history hold');
$freshRecords=OwnerRecords::forDomain($d->fresh());
same('new',$freshRecords['dns'][0]['address'],'Fresh inventory is visible after overflow');
same(8443,$freshRecords['srv'][0]['port'],'Fresh SRV inventory is visible after overflow');
same(2,count($freshRecords['ds']),'Fresh DS inventory is visible after overflow');
same($clock->now()->format(DATE_ATOM),$d->fresh()->observation['records']['at'],'Fresh inventory advances its timestamp');
same([$directHost=>'1.1.1.1'],array_column($freshRecords['glue'],'ip','host'),'Fresh discovery keeps the live direct host and cannot resurrect either delete');


$nativeSuccessful=true;echo "Exact operation-history boundary, matched sections, fresh glue and non-resurrecting deletes passed.\n";
