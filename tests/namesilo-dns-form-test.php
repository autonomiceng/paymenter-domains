<?php
// NameSilo DNS through the shared owner and staff records editor. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
require_once __DIR__.'/Support/raw-input.php';
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
staffPanel();storefrontViews();
$wire=(object)['records'=>[],'writes'=>[],'fault'=>'','next'=>0];
$dnsExchange=static function($origin,$cmd,$p,$timeout)use($wire,$managementExchange):string {
    if(!str_starts_with($cmd,'dns')){return $managementExchange($origin,$cmd,$p,$timeout);}
    $reply=['code'=>300,'detail'=>'synthetic'];
    if($cmd==='dnsListRecords'){
        if($wire->fault!=='missing'){$reply['resource_record']=array_map(fn($r)=>['record_id'=>$r['record_id'],'type'=>$r['type'],'host'=>($r['name']==='@'?'':$r['name'].'.').$p['domain'],'value'=>$r['address'],'ttl'=>$r['ttl'],'distance'=>$r['mx_pref']],$wire->records);}
    }else{
        $wire->writes[]=$cmd;$old=null;
        foreach($wire->records as $i=>$r){if($r['record_id']===($p['rrid']??null)){$old=$r;unset($wire->records[$i]);}}
        if($cmd!=='dnsDeleteRecord'){$id='made'.(++$wire->next);$reply['record_id']=$id;
            $wire->records[]=['name'=>$p['rrhost']===''?'@':$p['rrhost'],'type'=>$p['rrtype']??$old['type'],'address'=>$p['rrvalue'],'mx_pref'=>$p['rrdistance']??10,'record_id'=>$id,'ttl'=>$p['rrttl']];}
        $wire->records=array_values($wire->records);
        if($wire->fault==='lost'){throw new RuntimeException('Synthetic lost DNS response');}
    }
    return json_encode(['request'=>['operation'=>$cmd],'reply'=>$reply],JSON_THROW_ON_ERROR);
};
$a=['name'=>'@','type'=>'A','address'=>'192.0.2.1','mx_pref'=>10,'record_id'=>'original','ttl'=>300];
$txt=['name'=>'@','type'=>'TXT','address'=>'v=spf1 -all','mx_pref'=>10,'record_id'=>'text','ttl'=>3600];
$caa=['name'=>'@','type'=>'CAA','address'=>'0:issue:ca.example.com','mx_pref'=>10,'record_id'=>'opaque','ttl'=>600];
$srv=['name'=>'_sip._tcp','type'=>'SRV','address'=>'5:5060:sip.example.com.','mx_pref'=>20,'record_id'=>'service','ttl'=>900];
$cname=['name'=>'www','type'=>'CNAME','address'=>'example.net.','mx_pref'=>10,'ttl'=>7207];
$driver=null;$ordinary=null;$ports=new OwnerPorts(function($b)use(&$driver){return $driver;});$owners=new OwnedDomains($clock,$ports,$management);
app()->instance(OwnerPorts::class,$ports);app()->instance(OwnedDomains::class,$owners);app()->instance(DomainManagement::class,$management);
$staff=staffUser([Staff::VIEW,'admin.domains.manage']);Auth::setUser($staff);
$session=app('request')->session();$session->put('_token','owner-csrf');
$controller=new OwnerActionController($owners,new OwnerSetup($clock,$ports),new TransferInputs($clock,new Refunds($clock)),$management,$ports);
$request=function()use(&$d,$user,$session):Request {$r=Request::create('/domains/'.$d->id);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);return $r;};
$page=function()use(&$d,$owners,$request){storefrontViews();return storefrontDom((new OwnedDomainController($owners))->show($request(),$d->id)->render());};
$text=fn($node)=>trim(preg_replace('/\s+/u',' ',$node?->textContent??''));
$section=function()use(&$d,$owners){return $owners->present($d->fresh())->management['dns']??null;};
$form=fn()=>array_map(fn($row,$i)=>[...array_map('strval',$row),'_source'=>(string)$i],$section()['records'],array_keys($section()['records']));
$post=function(array $rows)use(&$d,$user,$session,$controller,$section) {
    $data=['confirmed'=>'1','records'=>$rows,'preimage_digest'=>$section()['digest']];
    $r=Request::create('/domains/'.$d->id.'/manage/dns','POST',$data,[],[],['CONTENT_TYPE'=>'application/x-www-form-urlencoded'],http_build_query($data));
    nativeNormalizeInput($r);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);$r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':dns'));
    return $controller->manage($r,$d->id,'dns');
};
$setup=function(string $label,array $rows,bool $on=true,array $nameservers=['NS1.DNSOWL.COM.','ns2.dnsowl.com','ns3.dnsowl.com'])use($holding,&$binding,$wire,$dnsExchange,&$driver,&$d,&$ordinary,$ms,$clock) {
    [$d,$ordinary]=$holding($label);\App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>true]);
    $ms->ns=$nameservers;$d->observation=ObservationProjection::recordNameservers($d,NameserverEvidence::observed($nameservers,'namesilo_domain_info',$clock->now()));$d->save();
    $wire->records=$rows;$wire->writes=[];$wire->fault='';$driver=$on?new DnsManagement($binding,$dnsExchange):$ordinary;
};
$staffPage=function()use(&$d,$staff){return Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]);};
$progress=function(string $want,bool $warning=false)use(&$d,$owners,$request,$page,$text,$staffPage):void {
    $row=(new OwnedDomainController($owners))->status($request(),$d->id)->getData(true)['dns']??null;same($want,$row['text']??null,'Owner polling shows the request number and counts');
    same($warning,($row['tone']??null)==='warning','Amber only where a person must act');
    $status=$page()->query('//li[@id="dns"]//*[@role="status"]')->item(0);same($want,$text($status),'Owner page shows the same words');
    same($warning,str_contains((string)$status?->getAttribute('class'),'domains-tone-text-warn'),'Owner tone matches');
    $staff=$staffPage();same($row,$staff->instance()->managementProgress()['dns'],'Staff and owner share every progress fact');
    expect(str_contains(html_entity_decode($staff->html(),ENT_QUOTES|ENT_HTML5),$want),'Staff page shows the same words');
};
$latest=function()use(&$d){return $d->operations()->where('action','dns')->orderByDesc('id')->first();};
// The installed adapter keeps DNS closed, and the page says so instead of hiding the row.
$setup('dns-form-off',[$a],false);
same([],$section()['actions']??null,'Dormant NameSilo DNS offers no change');
$row=$page()->query('//li[@id="dns"]')->item(0);
expect(str_contains($text($row),'Not available You can’t change DNS records here yet. Contact support for a change.'),'Owner row says DNS is not available and what to do');
same(0,$page()->query('//li[@id="dns"]//form')->length,'No records form while the capability is off');
$off='You can’t change DNS records here yet. Contact support for a change.';
expect(str_contains(html_entity_decode($staffPage()->html(),ENT_QUOTES|ENT_HTML5),$off),'Staff sees the same reason and next step');
// The opt-in editor needs observed default delegation, even with a complete saved inventory.
$setup('dns-form-external',[],true,['ns1.provider.example.net','ns2.provider.example.net']);
$seed=$submit($d,$driver,'dns',['records'=>[array_diff_key($a,['record_id'=>true])]]);Cache::flush();same('succeeded',$management->run($seed->id,$driver)->state,'Synthetic external-delegation inventory is saved');
same(null,$section(),'External nameservers hide the NameSilo DNS editor');
same(0,$page()->query('//li[@id="dns"]//form')->length,'External nameservers offer no DNS form');
foreach(['mixed'=>NameserverEvidence::observed(['ns1.dnsowl.com','ns2.provider.example.net'],'namesilo_domain_info',$clock->now()),
    'unconfirmed'=>NameserverEvidence::requested(['ns1.dnsowl.com','ns2.dnsowl.com','ns3.dnsowl.com'],'registration_request',$clock->now())] as $delegation=>$proof){
    $d->observation=ObservationProjection::recordNameservers($d,$proof);$d->save();
    same(null,$section(),'NameSilo DNS stays hidden on '.$delegation.' delegation');
}
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
// Removing the last record is done only once an explicit empty inventory proves it.
$setup('dns-form-last',[]);$seed=$submit($d,$driver,'dns',['records'=>[array_diff_key($a,['record_id'=>true])]]);Cache::flush();$management->run($seed->id,$driver);$wire->writes=[];
$rows=$form();same(1,count($rows),'One saved record');$rows[0]['_delete']='1';$post($rows);$op=$latest();
$wire->fault='lost';Cache::flush();same('uncertain',$management->run($op->id,$driver)->state,'A lost delete is not done');
$unknown='Request #'.$op->id.': 0 of 1 change verified. The result is not confirmed. Contact support before making more changes.';$progress($unknown,true);
$ms->route='apibatch';$wire->fault='missing';Cache::flush();same('uncertain',$management->reconcile($op->id,$driver)->state,'A missing list does not prove the delete');$progress($unknown,true);
$wire->fault='';Cache::flush();same('succeeded',$management->reconcile($op->id,$driver)->state,'An explicit empty list proves the delete');
$progress('Request #'.$op->id.': 1 change verified.');same([],$section()['records'],'The editor opens empty');same(['dnsDeleteRecord'],$wire->writes,'The delete is sent once');
expect(str_contains($text($page()->query('//li[@id="dns"]/span[contains(@class,"domains-row__v")]')->item(0)),'0 records'),'The row shows no records');
$nativeSuccessful=true;echo "NameSilo DNS forms passed\n";
