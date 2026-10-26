<?php
// Enom 444f31c glue-records and single-DS regression scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\ManagementPayload;
[$d,$driver]=$holding(placeholderComFixtureName('record-owner-placeholder.com'));storefrontViews();
$server=App\Models\Server::findOrFail($d->server_id);$server->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$dto=$owners->present($d->fresh());same(null,$dto->management['dns']['records'],'Unknown DNS is held, never an empty authoritative list');
expect(!$dto->changesUnavailable,'Available DS/child-host additions suppress the settings-unavailable notice');
$html=$page();same(['Missing',($dto->management['dns']['recordCheckHeld']??false)?'Shows when our team resumes record checks':'Shows after the next sync'],array_map(fn($n)=>trim($n->textContent),iterator_to_array(storefrontDom($html)->query('//li[@id="dns"]/span[@class="domains-row__v"]/span[@class="domains-missing" or @class="domains-sub"]'))),'Held DNS shows Missing with its cause');
same(0,storefrontDom($html)->query('//form[contains(@action,"/manage/dns")]')->length,'Held DNS has no replacement form');
$dom=storefrontDom($html);same(0,$dom->query('//form[contains(@action,"/manage/srv")]')->length,'Held SRV has no replacement form');
same(1,$dom->query('//li[@id="ds"]/span[@class="domains-row__v"]/span[@class="domains-missing"]')->length,'Unknown DS list is Missing');same('Add DS record',trim((string)$dom->query('//li[@id="ds"]/span[@class="domains-row__a"]/a')->item(0)?->textContent),'Unknown DS still offers an addition');same(1,$dom->query('//form[contains(@action,"/manage/ds_add")]')->length,'Unknown DS permits fresh-preflight addition');same(0,$dom->query('//form[contains(@action,"/manage/ds_delete")]')->length,'Unknown DS cannot imply deletable records');
expect(str_contains($html,'Register your own nameservers, like ns1.example.com, with their IP addresses.'),'Glue records intro is one sentence');same(1,$dom->query('//form[contains(@action,"/manage/glue_add")]')->length,'Unknown glue permits verified additions');same(0,$dom->query('//form[contains(@action,"/manage/glue_update")]')->length,'Unknown glue never fabricates editable hosts');
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$observer=new \Paymenter\Extensions\Servers\Domains\Services\DomainObservation($readBindings,$clock,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock),$ports);
same('refreshed',$observer->run($d->id),'Initial producer proves complete empty records before editing');
$cases=[
    'dns'=>['records'=>[['name'=>'@','type'=>'TXT','address'=>' exact + % é雪  text ','mx_pref'=>''],[]],'preimage_digest'=>ManagementPayload::digest([])],
    'srv'=>['records'=>[['service'=>'sip','protocol'=>'tcp','priority'=>'0','weight'=>'5','port'=>'443','target'=>'sip.example.com.']],'preimage_digest'=>ManagementPayload::digest([],'srv')],
    'ds_add'=>['ds'=>$ds], 'ds_delete'=>['ds'=>$ds],
    'glue_add'=>['host'=>'ns1.record-owner-placeholder.com','ip'=>'2606:4700:4700::1111'],
    'glue_update'=>['host'=>'ns1.record-owner-placeholder.com','ip'=>'8.8.8.8','old_ip'=>'127.0.0.1'],
    'glue_delete'=>['host'=>'ns1.record-owner-placeholder.com'],
];
foreach($cases as$action=>$payload){
    Cache::flush();$before=Operation::count();$io=count($state->calls);$r=$post($action,$payload);$stale=clone $r;
    $controller->manage($r,$d->id,$action);same($before+1,Operation::count(),$action.' creates one durable operation');same($io,count($state->calls),'POST never dispatches');
    $op=$d->operations()->orderByDesc('id')->firstOrFail();same('queued',$op->state,'Queued is not sent');
    try{$controller->manage($stale,$d->id,$action);expect(false,'Replay refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Single-use action key');}
    same('succeeded',$management->run($op->id,$driver)->state,$action.' uses existing grant and typed readback');
    if($action==='dns'){same(' exact + % é雪  text ',$op->payload['records'][0]['address'],'TXT bytes preserved');expect(str_contains($page(),' exact + % é雪  text '),'Confirmed records reach real Blade');}
    if($action==='ds_add'){same(1,count($owners->present($d->fresh())->management['ds']['records']),'Single DS tuple renders');}
    if($action==='glue_update'){$writes=array_values(array_filter($state->calls,fn($c)=>$c[0]==='UpdateNameServer'));same('2606:4700:4700::1111',end($writes)[1]['OldIP'],'Old IP comes from fresh preflight');}
}
// Preserve proven SRV HostIDs server-side through an edited browser row, never in HTML.
$known=$d->operations()->where('action','srv')->sole();$attempt=$known->attempt;$attempt['management_reads'][0]['facts']['records'][0]['host_id']=31;
// Fixture-only encrypted evidence augmentation through SQL, avoiding any production evidence mutation path.
$known->attempt=$attempt;Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$known->id)->update(['attempt'=>$known->getAttributes()['attempt']]);
$html=$page();expect(!str_contains($html,'host_id')&&!str_contains($html,'HostID'),'Provider IDs stay out of HTML');
$form=storefrontDom($html);$formPath='//form[contains(@action,"/manage/srv")]';
$renderDigest=$form->query($formPath.'//input[@name="preimage_digest"]')->item(0)->getAttribute('value');
$record=$owners->present($d->fresh())->management['srv']['records'][0];$record['port']=8443;
$record['_source']=$form->query($formPath.'//input[@name="records[0][_source]"]')->item(0)->getAttribute('value');$record['host_id']=999;
// A confirmed readback arriving after render inserts a different identity before source 0.
$earlier=[...$attempt['management_reads'][0]['facts']['records'][0],'service'=>'aaa','host_id'=>19];
$attempt['management_reads'][0]['facts']['records'][]=$earlier;
$known->attempt=$attempt;Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$known->id)->update(['attempt'=>$known->getAttributes()['attempt']]);
$state->srv[]=$earlier;$current=$owners->present($d->fresh())->management['srv'];
same(['aaa','sip'],array_column($current['records'],'service'),'New confirmed record shifts displayed source indexes');
same(ManagementPayload::digest($attempt['management_reads'][0]['facts']['records'],'srv'),$current['digest'],'Form and identity lookup digest the same canonical row set');
$before=Operation::count();$payloads=Operation::orderBy('id')->get()->map(fn($op)=>$op->payload)->all();$io=count($state->calls);
Cache::flush();$response=$controller->manage($post('srv',['records'=>[$record],'preimage_digest'=>$renderDigest]),$d->id,'srv');
same(422,$response->getStatusCode(),'Stale SRV source indexes require a reload before queueing');
expect(str_contains($response->getContent(),'We couldn’t save this change. Reload the page and try again.'),'Stale source error is visible');
same($before,Operation::count(),'Stale source creates no operation');same($payloads,Operation::orderBy('id')->get()->map(fn($op)=>$op->payload)->all(),'Stale source creates or changes no frozen payload');
same($io,count($state->calls),'Stale source validation performs no provider I/O');
try { \Paymenter\Extensions\Servers\Domains\Services\OwnerRecords::restoreSrv($d->fresh(),['records'=>[$record],'record_sources'=>['0'],'preimage_digest'=>$renderDigest]);expect(false,'Stale source must fail field validation'); }
catch (\Paymenter\Extensions\Servers\Domains\Services\OwnerFormValidation $error) { same(['srv.records'],$error->fields,'Stale source identifies the reload field'); }
// Reloaded indexes/digest still select the original HostID, even though raw readback order differs.
$record['_source']='1';Cache::flush();$r=$post('srv',['records'=>[$record],'preimage_digest'=>$current['digest']]);$controller->manage($r,$d->id,'srv');
$op=$d->operations()->orderByDesc('id')->firstOrFail();same(31,$op->payload['records'][0]['host_id'],'Proven server-side ID replaces spoofed browser ID');same('succeeded',$management->run($op->id,$driver)->state,'SRV edit confirmed');
$writes=array_values(array_filter($state->calls,fn($c)=>$c[0]==='SetDomainSRVHosts'));same(31,end($writes)[1]['HostID1'],'Retained HostID dispatched');
// Fifty rows, long label, safe escaping, unique labels and native security inputs in actual Blade.
$rows=array_map(fn($i)=>['name'=>'record'.$i,'type'=>'TXT','address'=>'<synthetic> & text','mx_pref'=>10],range(1,50));
Cache::flush();$r=$post('dns',['records'=>$rows,'preimage_digest'=>ManagementPayload::digest($state->dns)]);$controller->manage($r,$d->id,'dns');
$op=$d->operations()->orderByDesc('id')->firstOrFail();same('succeeded',$management->run($op->id,$driver)->state,'Maximum DNS records through native controller');
$dto=$owners->present($d->fresh());$long=new \Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(...[...get_object_vars($dto),'name'=>placeholderComFixtureName((str_repeat('a',51).'-placeholder').'.com')]);
$html=view('domains::show',['domain'=>$long,'keys'=>array_fill_keys(['setup','recovery','code','renew','auto-renew',...\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS],str_repeat('a',64))])->render();$dom=storefrontDom($html);
same(50,$dom->query('//li[@id="dns"]//tbody/tr')->length,'Maximum is bounded to 50 visible rows');expect(str_contains($html,(str_repeat('a',51).'-placeholder')),'Long label preserved');expect(!str_contains($html,'value="<synthetic>'),'Record values escaped');
$ids=[];foreach($dom->query('//*[@id]')as$node){expect(!isset($ids[$node->getAttribute('id')]),'Unique field IDs');$ids[$node->getAttribute('id')]=true;}
foreach($dom->query('//*[@aria-describedby]')as$node){foreach(explode(' ',$node->getAttribute('aria-describedby'))as$id){expect(isset($ids[$id]),'Every description exists');}}
$forms=$dom->query('//form[@method="POST"]')->length;
foreach(['_token','_request_key']as$key){same($forms,$dom->query('//form//input[@name="'.$key.'"]')->length,'Every form has '.$key);}
same(0,$dom->query('//form//input[@type="checkbox"]')->length,'Record forms have no checkboxes');
same($dom->query('//form[@data-confirm]')->length,$dom->query('//form[@data-confirm]//dialog//button[@type="submit"][@name="confirmed"][@value="1"]')->length,'Only the confirm step sends the confirmation field');
same(0,$dom->query('//input[@name="confirmed"]')->length,'No form carries a hidden or ticked confirmation');
foreach(['account_fingerprint','provider_domain_id','old_ip','profile_id','cost']as$private){expect(!str_contains($html,'name="'.$private.'"'),'No private authority serialized');}
expect(str_contains($html,'DNS records')&&str_contains($html,'SRV records')&&str_contains($html,'DNSSEC')&&str_contains($html,'Glue records')&&!str_contains($html,'Child nameservers'),'All supported sections rendered');
// SRV whitespace must reach strict validation instead of being silently repaired by middleware.
$before=Operation::count();$r=$post('srv',['records'=>[['service'=>'sip','protocol'=>'tcp','priority'=>'0','weight'=>'5','port'=>'443','target'=>' sip.example.com. ']],'preimage_digest'=>ManagementPayload::digest($state->srv,'srv')]);
$response=$controller->manage($r,$d->id,'srv');same(422,$response->getStatusCode(),'Original SRV target whitespace is invalid');same($before,Operation::count(),'Trimming cannot turn invalid SRV input into a queued change');
$before=Operation::count();$r=$post('dns',['records'=>[...$rows,$rows[0]],'preimage_digest'=>ManagementPayload::digest($state->dns)]);$controller->manage($r,$d->id,'dns');same($before,Operation::count(),'Fifty-one rows rejected before any operation');
// Pure adds retain the existing queued-then-preflight behavior for a stale digest.
$before=Operation::count();Cache::flush();$r=$post('srv',['records'=>[array_diff_key($record,['_source'=>true,'host_id'=>true])],'preimage_digest'=>str_repeat('a',64)]);
same(302,$controller->manage($r,$d->id,'srv')->getStatusCode(),'Pure-add stale preimage remains a queued request');same($before+1,Operation::count(),'Pure add still creates an operation');
$op=$d->operations()->orderByDesc('id')->firstOrFail();expect(!isset($op->payload['records'][0]['host_id']),'Pure add acquires no provider identity');
$io=count(array_filter($state->calls,fn($c)=>$c[0]==='SetDomainSRVHosts'));same('held',$management->run($op->id,$driver)->state,'Existing dispatch preflight holds a stale pure add');
same($io,count(array_filter($state->calls,fn($c)=>$c[0]==='SetDomainSRVHosts')),'Stale pure add never writes');
$server->settings()->where('key','domains_dns_tools')->update(['value'=>'0']);$dto=$owners->present($d->fresh());foreach(['dns','srv','ds']as$kind){expect(!isset($dto->management[$kind]),'Tools off removes each action');}expect(isset($dto->management['glue']),'Glue follows its own engine authority');
$nativeSuccessful=true;echo "Native owner DNS/SRV/DS/glue grants, replay, exact TXT, current preimage, safe real Blade and maximum records passed.\n";
