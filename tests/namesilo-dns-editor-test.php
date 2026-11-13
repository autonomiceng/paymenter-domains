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
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{DnsForm,DnsManagement};
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ObservationProjection,OwnedDomains,OwnerPorts,OwnerSetup,Refunds,TransferInputs};
// A middle change that used its five unsent tries keeps the editor after a settings change, and a fresh save finishes the rest.
$setup('dns-drift',[$a,$txt,$caa,$srv]);$seed=$submit($d,$driver,'dns',['records'=>[$a,$txt,$cname]]);Cache::flush();$management->run($seed->id,$driver);
$rows=$form();$rows[0]['address']='192.0.2.5';$rows[1]['address']='v=spf1 a -all';$rows[]=['name'=>'mail','type'=>'A','address'=>'192.0.2.6','mx_pref'=>'','_source'=>''];$post($rows);$op=$latest();
$steps=$d->operations()->where('action','dns')->where('id','<=',$op->id)->orderByDesc('id')->limit(3)->get()->reverse()->values();
Cache::flush();$management->run($op->id,$driver);same('succeeded',$steps[0]->fresh()->state,'The first change is confirmed');$wire->fault='missing';
for($round=0;$round<8&&$steps[1]->fresh()->state!=='rejected';$round++){$clock->instant=$clock->now()->modify('+16 minutes');Cache::flush();$management->run($op->id,$driver);}
same(['rejected','management_preparation_failed','queued'],[$steps[1]->fresh()->state,$steps[1]->fresh()->safe_code,$op->fresh()->state],'The middle change stops after five unsent tries');
$wire->fault='';\App\Models\Server::findOrFail($d->server_id)->settings()->create(['key'=>'synthetic_revision','value'=>'changed']);Cache::flush();
$want='Request #'.$op->id.': 1 of 3 changes verified. The remaining changes were not sent. Save again to retry.';
$row=(new OwnedDomainController($owners))->status($request(),$d->id)->getData(true)['dns']??null;same([$want,false],[$row['text']??null,$row['pending']??null],'Owner polling says the rest was not sent and frees the row');
$dom=$page();same($want,$text($dom->query('//li[@id="dns"]//*[@role="status"]')->item(0)),'The owner page says the same and how to retry');same(0,$dom->query('//li[@id="dns"]//*[@data-management-pending]')->length,'The unsent request does not hold the row');
same(1,$dom->query('//li[@id="dns"]//a[@data-dialog-open]')->length,'The owner can open the DNS editor');same(1,$dom->query('//li[@id="dns"]//form')->length,'The editor form is on the page');
$wire->writes=[];$rows=$form();$at=fn($type)=>array_search($type,array_column($rows,'type'),true);
same('192.0.2.5',$rows[$at('A')]['address'],'The editor shows the confirmed change');$rows[$at('TXT')]['address']='v=spf1 a -all';$rows[]=['name'=>'mail','type'=>'A','address'=>'192.0.2.6','mx_pref'=>'','_source'=>''];$post($rows);$fresh=$latest();
expect($fresh->id!==$op->id,'A fresh save replaces the unsent request');same('management_retired',$op->fresh()->safe_code,'The old request is closed');
for($i=0;$i<3&&$fresh->fresh()->state!=='succeeded';$i++){Cache::flush();$management->run($fresh->id,$driver);}
same('succeeded',$fresh->fresh()->state,'The fresh request completes');same(['dnsUpdateRecord','dnsAddRecord'],$wire->writes,'Only the remaining changes are sent');
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
$fixed=array_map(fn($row)=>array_map($text,iterator_to_array($row->getElementsByTagName('td'))),iterator_to_array($dom->query('//li[@id="dns"]//*[@data-fixed-records]')));
// A complete read lists CAA last with the other DNS records, and every read-only row shows the TTL that read returned.
same([['_sip._tcp','SRV','5:5060:sip.example.com.','','900','Read-only'],['@','CAA','0:issue:ca.example.com','','600','Read-only']],$fixed,'Every read-only row has its type, value and read TTL');
// Saved editable rows show the TTL they keep; blank rows get the registrar default.
$ttls=fn($dom,$rows)=>array_map(fn($row)=>$text($row->getElementsByTagName('td')->item(4)),iterator_to_array($dom->query('//li[@id="dns"]//table[contains(@class,"domains-records")]/tbody/tr'.$rows)));
same(['300','3600','7207'],$ttls($dom,'[@data-record]'),'Saved editable rows show the TTL the registrar read returned');
same(['Registrar default','Registrar default'],$ttls($dom,'[not(@data-record)][not(@data-fixed-records)]'),'New rows say they get the registrar default');
same([['A','300'],['TXT','3600'],['CNAME','7207']],array_map(fn($row)=>[$row->getElementsByTagName('td')->item(4)->getAttribute('data-saved-type'),$row->getElementsByTagName('td')->item(4)->getAttribute('data-saved-ttl')],iterator_to_array($dom->query('//li[@id="dns"]//table[contains(@class,"domains-records")]/tbody/tr[@data-record]'))),'Each saved row names the type its TTL is kept for, so a type change can show the default');
$access=[];foreach($dom->query('//li[@id="dns"]//table[contains(@class,"domains-dns-inventory")]/tbody/tr') as $row){$access[$text($row->getElementsByTagName('td')->item(1))]=$text($row->getElementsByTagName('td')->item(4));}
same(['A'=>'Edit above','TXT'=>'Edit above','SRV'=>'Read-only','CNAME'=>'Edit above','CAA'=>'Read-only'],$access,'The records table marks only standard rows as editable');
$notes=array_map($text,iterator_to_array($dom->query('//li[@id="dns"]//p[@class="domains-hint"]')));
foreach(['SRV records can’t be changed here yet, because we can’t read their priority. Saving keeps them as they are. To change one, contact support.',
    'CAA records can’t be changed here yet. Saving keeps them as they are. To change one, contact support.',
    'A saved row keeps its TTL while its type stays the same. A new row, or a saved row with a new type, gets the registrar default.'] as $note){expect(in_array($note,$notes,true),'Each read-only type has its complete reason and next step');}
// One edited row changes; every other row, ID and TTL stays.
$rows=$form();$rows[0]['address']='192.0.2.2';$post($rows);$op=$latest();
same([],$wire->writes,'The form save only reads');Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'The edit completes');
same(['dnsUpdateRecord'],$wire->writes,'Only the edited row is written');
$by=fn($type)=>array_values(array_filter($wire->records,fn($r)=>$r['type']===$type))[0]??null;
same([$caa,$srv,$txt],[$by('CAA'),$by('SRV'),$by('TXT')],'Read-only rows and untouched TTLs stay exactly as they were');
same(['192.0.2.2',300],[$by('A')['address'],$by('A')['ttl']],'The edited row keeps its own TTL');same(7207,$by('CNAME')['ttl'],'An untouched row keeps its TTL');
$count=$d->operations()->count();$rows=$form();$rows[]=['name'=>'go','type'=>'URL','address'=>'https://example.com','mx_pref'=>'','_source'=>''];
$kept=array_map(fn($r)=>(string)$by($r['type'])['ttl'],array_slice($rows,1,2));$rows[0]=[...$rows[0],'type'=>$rows[0]['type']==='A'?'TXT':'A','address'=>$rows[0]['type']==='A'?'v=spf1 a -all':'192.0.2.9'];
$refused=$post($rows);same(422,$refused->getStatusCode(),'A type this registrar has no record for is refused in the form');
same(['Registrar default',...$kept,'Registrar default'],array_slice($ttls(storefrontDom($refused->getContent()),'[not(@data-fixed-records)]'),0,4),'The returned form keeps each saved TTL, and a changed type or new row gets the default');same($count,$d->operations()->count(),'A refused type queues nothing');
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
$staffPage()->callAction(TestAction::make('edit_dns')->schemaComponent('relationManagerTabs.sheet','content'),['records'=>$rows,'preimage_digest'=>$section()['digest']])->assertHasNoActionErrors();
$op=$latest();same((int)$staff->id,(int)$op->authorized_by,'The staff save is one request');Cache::flush();$management->run($op->id,$driver);
$progress('Request #'.$op->id.': 1 change verified.');same($caa,$by('CAA'),'Staff edits keep read-only rows');
// A valid save that changes a saved row's type sends that row as new, with the registrar default TTL; other rows stay.
$rows=$form();$i=array_search('TXT',array_column($rows,'type'),true);expect($i!==false,'A saved TXT row is on the form');
$cell=$page()->query('//li[@id="dns"]//table[contains(@class,"domains-records")]/tbody/tr[@data-record]')->item($i)->getElementsByTagName('td')->item(4);
same(['TXT',(string)$by('TXT')['ttl']],[$cell->getAttribute('data-saved-type'),$text($cell)],'The saved TXT row shows its kept TTL before the change');
$others=array_values(array_filter($wire->records,fn($r)=>$r['type']!=='TXT'));$sent=count($wire->writes);$count=$d->operations()->count();
$rows[$i]=[...$rows[$i],'type'=>'A','address'=>'192.0.2.9'];same(302,$post($rows)->getStatusCode(),'A changed type to an editable type is accepted');
expect($d->operations()->count()>$count,'The type change is queued');$op=$latest();
for($n=0;$n<5&&$op->fresh()->state!=='succeeded';$n++){Cache::flush();$management->run($op->id,$driver);}
same('succeeded',$op->fresh()->state,'The type change completes');
$writes=array_slice($wire->writes,$sent);sort($writes);same(['dnsAddRecord','dnsDeleteRecord'],$writes,'The old record is removed and the new type is added, not updated in place');
$made=array_values(array_filter($wire->records,fn($r)=>$r['address']==='192.0.2.9'));
same(1,count($made),'One record has the new value');same(['A',DnsForm::TTL],[$made[0]['type'],$made[0]['ttl']],'The changed row gets the registrar default TTL, not the saved one');
same(null,$by('TXT'),'The old TXT record is gone');
$sortById=function(array $rs){usort($rs,fn($x,$y)=>strcmp($x['record_id'],$y['record_id']));return $rs;};
same($sortById($others),$sortById(array_values(array_filter($wire->records,fn($r)=>$r['address']!=='192.0.2.9'))),'Every other row keeps its ID and TTL');
// Saved records stay counted when DNS is closed again, and both sides still say why and what to do.
// The confirmed save is the last records check, so the owner page gives its time once in the Checked line.
$driver=$ordinary;$dom=$page();$row=$text($dom->query('//li[@id="dns"]/span[contains(@class,"domains-row__v")]')->item(0));$checked=$text($dom->query('//p[starts-with(normalize-space(.),"Checked ")]')->item(0));
expect(str_starts_with($row,'4 records')&&str_starts_with($checked,'Checked ')&&str_ends_with($row,$off),'Owner keeps the count and its time and gets the reason and next step');same(0,$page()->query('//li[@id="dns"]//form')->length,'No records form with saved records either');
$html=html_entity_decode($staffPage()->html(),ENT_QUOTES|ENT_HTML5);expect(str_contains($html,'4 records')&&str_contains($html,'As of ')&&str_contains($html,$off),'Staff keep the count and get the reason and next step');
$nativeSuccessful=true;echo "namesilo-dns-editor-test.php passed\n";
