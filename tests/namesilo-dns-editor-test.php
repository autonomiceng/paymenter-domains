<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic shared DNS editor coverage.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-dns-form.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsManagement;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ObservationProjection,OwnedDomains,OwnerPorts,OwnerSetup,Refunds,TransferInputs};
// The opt-in adapter edits standard rows in the shared editor; CAA and SRV stay read-only.
$setup('dns-form',[$a,$txt,$caa,$srv]);
$seed=$submit($d,$driver,'dns',['records'=>[$a,$txt,$cname]]);Cache::flush();same('succeeded',$management->run($seed->id,$driver)->state,'Synthetic seed save completes');$wire->writes=[];
same([['name'=>'@','type'=>'A','address'=>'192.0.2.1','mx_pref'=>10],['name'=>'@','type'=>'TXT','address'=>'v=spf1 -all','mx_pref'=>10],['name'=>'www','type'=>'CNAME','address'=>'example.net.','mx_pref'=>10]],
    $section()['records'],'Standard rows are editable without provider IDs or TTLs');
$dom=$page();$html=$dom->document->saveHTML();
expect(str_contains($text($dom->query('//li[@id="dns"]/span[contains(@class,"domains-row__v")]')->item(0)),'5 records'),'The row counts every record, read-only ones too');
same(['','A','AAAA','CNAME','MX','TXT'],array_map(fn($o)=>$o->getAttribute('value'),iterator_to_array($dom->query('//select[@name="records[0][type]"]/option'))),'Only standard types are offered');
same(['0','1','2'],array_map(fn($i)=>$i->getAttribute('value'),iterator_to_array($dom->query('//li[@id="dns"]//input[contains(@name,"[_source]")]'))),'Saved rows carry their place, not a provider ID');
expect(!str_contains($html,'original')&&!str_contains($html,'opaque'),'Provider record IDs stay server-side');
$fixed=$text($dom->query('//li[@id="dns"]//*[@data-fixed-records]')->item(0));
expect(str_contains($fixed,'_sip._tcp SRV')&&str_contains($fixed,'SRV records can’t be changed here yet, because we can’t read their priority. Saving keeps them as they are. To change one, contact support.'),'SRV is read-only with a plain reason and next step');
expect(str_contains($fixed,'@ CAA 0:issue:ca.example.com'),'CAA is listed read-only');
// One edited row changes; every other row, ID and TTL stays.
$rows=$form();$rows[0]['address']='192.0.2.2';$post($rows);$op=$latest();
same([],$wire->writes,'The form save only reads');Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'The edit completes');
same(['dnsUpdateRecord'],$wire->writes,'Only the edited row is written');
$by=fn($type)=>array_values(array_filter($wire->records,fn($r)=>$r['type']===$type))[0]??null;
same([$caa,$srv,$txt],[$by('CAA'),$by('SRV'),$by('TXT')],'Read-only rows and untouched TTLs stay exactly as they were');
same(['192.0.2.2',300],[$by('A')['address'],$by('A')['ttl']],'The edited row keeps its own TTL');same(7207,$by('CNAME')['ttl'],'An untouched row keeps its TTL');
$count=$d->operations()->count();$rows=$form();$rows[]=['name'=>'go','type'=>'URL','address'=>'https://example.com','mx_pref'=>'','_source'=>''];
same(422,$post($rows)->getStatusCode(),'A type this registrar has no record for is refused in the form');same($count,$d->operations()->count(),'A refused type queues nothing');
$wire->records=array_map(fn($r)=>$r['type']==='TXT'?[...$r,'ttl'=>301]:$r,$wire->records);$rows=$form();$rows[0]['address']='192.0.2.3';$post($rows);
same($count,$d->operations()->count(),'Records changed at the registrar since the last read are not overwritten');same(['dnsUpdateRecord'],$wire->writes,'A stale form sends nothing');
$wire->records=array_map(fn($r)=>$r['type']==='TXT'?$txt:$r,$wire->records);
// Partial progress: the same request number and counts on both pages.
$rows=$form();$rows[0]['address']='192.0.2.4';$rows[2]['_delete']='1';$post($rows);$op=$latest();Cache::flush();$management->run($op->id,$driver);
$progress('Request #'.$op->id.': 1 of 2 changes verified. Saving the remaining changes.');
Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'The second change completes');
$progress('Request #'.$op->id.': all 2 changes verified.');same(['dnsUpdateRecord','dnsUpdateRecord','dnsDeleteRecord'],$wire->writes,'One write per change');
// Staff save through the same editor and sequence.
$rows=$form();$rows[1]['address']='v=spf1 mx -all';
$staffPage()->callAction(TestAction::make('edit_dns')->schemaComponent('fact_'.md5('DNS records'),'infolist'),['records'=>$rows,'preimage_digest'=>$section()['digest']])->assertHasNoActionErrors();
$op=$latest();same((int)$staff->id,(int)$op->authorized_by,'The staff save is one request');Cache::flush();$management->run($op->id,$driver);
$progress('Request #'.$op->id.': 1 change verified.');same($caa,$by('CAA'),'Staff edits keep read-only rows');
// Saved records stay counted when DNS is closed again, and both sides still say why and what to do.
$driver=$ordinary;$row=$text($page()->query('//li[@id="dns"]/span[contains(@class,"domains-row__v")]')->item(0));
expect(str_starts_with($row,'4 records')&&str_contains($row,'As of ')&&str_ends_with($row,$off),'Owner keeps the count and gets the reason and next step');same(0,$page()->query('//li[@id="dns"]//form')->length,'No records form with saved records either');
$html=html_entity_decode($staffPage()->html(),ENT_QUOTES|ENT_HTML5);expect(str_contains($html,'4 records')&&str_contains($html,'As of ')&&str_contains($html,$off),'Staff keep the count and get the reason and next step');
$nativeSuccessful=true;echo "namesilo-dns-editor-test.php passed\n";
