<?php
// NameSilo DNS through the shared owner and staff records editor. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/management-namesilo.php';
require __DIR__.'/owner-pages.php';
require __DIR__.'/staff-panel.php';
require_once __DIR__.'/raw-input.php';
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
$off='You can’t change DNS records here yet. Contact support for a change.';
