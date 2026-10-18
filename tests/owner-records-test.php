<?php
// Enom 444f31c glue-records and single-DS regression scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\ManagementPayload;
[$d,$driver]=$holding('record-owner.com');storefrontViews();
$server=App\Models\Server::findOrFail($d->server_id);$server->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$dto=$owners->present($d->fresh());same(null,$dto->management['dns']['records'],'Unknown DNS is held, never an empty authoritative list');
expect(!$dto->changesUnavailable,'Available DS/child-host additions suppress the settings-unavailable notice');
$html=$page();expect(str_contains($html,'Editing isn’t available yet because we haven’t confirmed your current records.'),'Held hint is rendered');
same(0,storefrontDom($html)->query('//form[contains(@action,"/manage/dns")]')->length,'Held DNS has no replacement form');
$dom=storefrontDom($html);same(0,$dom->query('//form[contains(@action,"/manage/srv")]')->length,'Held SRV has no replacement form');
expect(str_contains($html,'Your current DS records can’t be shown right now. You can still add one.'),'Unknown DS list is explicit');same(1,$dom->query('//form[contains(@action,"/manage/ds_add")]')->length,'Unknown DS permits fresh-preflight addition');same(0,$dom->query('//form[contains(@action,"/manage/ds_delete")]')->length,'Unknown DS cannot imply deletable records');
expect(str_contains($html,'Only child nameservers added or changed here are listed. Others may already exist.'),'Unknown glue is explicitly partial history');same(1,$dom->query('//form[contains(@action,"/manage/glue_add")]')->length,'Unknown glue permits verified additions');same(0,$dom->query('//form[contains(@action,"/manage/glue_update")]')->length,'Unknown glue never fabricates editable hosts');
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$observer=new \Paymenter\Extensions\Servers\Domains\Services\DomainObservation($readBindings,$clock,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock),$ports);
same('refreshed',$observer->run($d->id),'Initial producer proves complete empty records before editing');
$cases=[
    'dns'=>['records'=>[['name'=>'@','type'=>'TXT','address'=>' exact  text ','mx_pref'=>''],[]],'preimage_digest'=>ManagementPayload::digest([])],
    'srv'=>['records'=>[['service'=>'sip','protocol'=>'tcp','priority'=>'0','weight'=>'5','port'=>'443','target'=>'sip.example.com.']],'preimage_digest'=>ManagementPayload::digest([],'srv')],
    'ds_add'=>['ds'=>$ds], 'ds_delete'=>['ds'=>$ds],
    'glue_add'=>['host'=>'ns1.record-owner.com','ip'=>'2606:4700:4700::1111'],
    'glue_update'=>['host'=>'ns1.record-owner.com','ip'=>'8.8.8.8','old_ip'=>'127.0.0.1'],
    'glue_delete'=>['host'=>'ns1.record-owner.com'],
];
foreach($cases as$action=>$payload){
    Cache::flush();$before=Operation::count();$io=count($state->calls);$r=$post($action,$payload);$stale=clone $r;
    $controller->manage($r,$d->id,$action);same($before+1,Operation::count(),$action.' creates one durable operation');same($io,count($state->calls),'POST never dispatches');
    $op=$d->operations()->orderByDesc('id')->firstOrFail();same('queued',$op->state,'Queued is not sent');
    try{$controller->manage($stale,$d->id,$action);expect(false,'Replay refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Single-use action key');}
    same('succeeded',$management->run($op->id,$driver)->state,$action.' uses existing grant and typed readback');
    if($action==='dns'){same(' exact  text ',$op->payload['records'][0]['address'],'TXT bytes preserved');expect(str_contains($page(),' exact  text '),'Confirmed records reach real Blade');}
    if($action==='ds_add'){same(1,count($owners->present($d->fresh())->management['ds']['records']),'Single DS tuple renders');}
    if($action==='glue_update'){$writes=array_values(array_filter($state->calls,fn($c)=>$c[0]==='UpdateNameServer'));same('2606:4700:4700::1111',end($writes)[1]['OldIP'],'Old IP comes from fresh preflight');}
}
// Preserve proven SRV HostIDs server-side through an edited browser row, never in HTML.
$known=$d->operations()->where('action','srv')->sole();$attempt=$known->attempt;$attempt['management_reads'][0]['facts']['records'][0]['host_id']=31;
// Fixture-only encrypted evidence augmentation through SQL, avoiding any production evidence mutation path.
$known->attempt=$attempt;Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$known->id)->update(['attempt'=>$known->getAttributes()['attempt']]);
$html=$page();expect(!str_contains($html,'host_id')&&!str_contains($html,'HostID'),'Provider IDs stay out of HTML');
$record=$owners->present($d->fresh())->management['srv']['records'][0];$record['port']=8443;$record['_source']='0';$record['host_id']=999;
Cache::flush();$r=$post('srv',['records'=>[$record],'preimage_digest'=>ManagementPayload::digest($state->srv,'srv')]);$controller->manage($r,$d->id,'srv');
$op=$d->operations()->orderByDesc('id')->firstOrFail();same(31,$op->payload['records'][0]['host_id'],'Proven server-side ID replaces spoofed browser ID');same('succeeded',$management->run($op->id,$driver)->state,'SRV edit confirmed');
$writes=array_values(array_filter($state->calls,fn($c)=>$c[0]==='SetDomainSRVHosts'));same(31,end($writes)[1]['HostID1'],'Retained HostID dispatched');
// Fifty rows, long label, safe escaping, unique labels and native security inputs in actual Blade.
$rows=array_map(fn($i)=>['name'=>'record'.$i,'type'=>'TXT','address'=>'<synthetic> & text','mx_pref'=>10],range(1,50));
Cache::flush();$r=$post('dns',['records'=>$rows,'preimage_digest'=>ManagementPayload::digest($state->dns)]);$controller->manage($r,$d->id,'dns');
$op=$d->operations()->orderByDesc('id')->firstOrFail();same('succeeded',$management->run($op->id,$driver)->state,'Maximum DNS records through native controller');
$dto=$owners->present($d->fresh());$long=new \Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(...[...get_object_vars($dto),'name'=>str_repeat('a',63).'.com']);
$html=view('domains::show',['domain'=>$long,'keys'=>array_fill_keys(\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS,str_repeat('a',64))])->render();$dom=storefrontDom($html);
same(50,$dom->query('//section[@id="dns"]//tbody/tr')->length,'Maximum is bounded to 50 visible rows');expect(str_contains($html,str_repeat('a',63)),'Long label preserved');expect(!str_contains($html,'value="<synthetic>'),'Record values escaped');
$ids=[];foreach($dom->query('//*[@id]')as$node){expect(!isset($ids[$node->getAttribute('id')]),'Unique field IDs');$ids[$node->getAttribute('id')]=true;}
foreach($dom->query('//*[@aria-describedby]')as$node){foreach(explode(' ',$node->getAttribute('aria-describedby'))as$id){expect(isset($ids[$id]),'Every description exists');}}
$forms=$dom->query('//form[@method="POST"]')->length;
foreach(['_token','_request_key','confirmed']as$key){same($forms,$dom->query('//form//input[@name="'.$key.'"]')->length,'Every form has '.$key);}
foreach(['account_fingerprint','provider_domain_id','old_ip','profile_id','cost']as$private){expect(!str_contains($html,'name="'.$private.'"'),'No private authority serialized');}
expect(str_contains($html,'DNS records')&&str_contains($html,'SRV records')&&str_contains($html,'DNSSEC')&&str_contains($html,'Child nameservers'),'All supported sections rendered');
// Native middleware preserves TXT bytes while trimming ordinary fields.
$r=Illuminate\Http\Request::create('/domains/1/manage/dns','POST',['records'=>[['name'=>' @ ','address'=>' exact  TXT ']]]);
$previous=app('request');app()->instance('request',$r); // Livewire's skip callback inspects the container request.
(new Illuminate\Foundation\Http\Middleware\TrimStrings)->handle($r,fn($r)=>$r);
app()->instance('request',$previous);
same(' exact  TXT ',$r->input('records.0.address'),'Native middleware must not trim TXT');same('@',$r->input('records.0.name'),'Ordinary inputs remain trimmed');
$before=Operation::count();$r=$post('dns',['records'=>[...$rows,$rows[0]],'preimage_digest'=>ManagementPayload::digest($state->dns)]);$controller->manage($r,$d->id,'dns');same($before,Operation::count(),'Fifty-one rows rejected before any operation');
$server->settings()->where('key','domains_dns_tools')->update(['value'=>'0']);$dto=$owners->present($d->fresh());foreach(['dns','srv','ds']as$kind){expect(!isset($dto->management[$kind]),'Tools off removes each action');}expect(isset($dto->management['glue']),'Glue follows its own engine authority');
$nativeSuccessful=true;echo "Native owner DNS/SRV/DS/glue grants, replay, exact TXT, current preimage, safe real Blade and maximum records passed.\n";
