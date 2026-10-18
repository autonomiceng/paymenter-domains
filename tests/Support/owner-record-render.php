<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic real-Blade record states.
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
use Paymenter\Extensions\Servers\Domains\Services\ManagementPayload;
$records=['dns'=>[['name'=>'@','type'=>'A','address'=>'8.8.8.8','mx_pref'=>10],['name'=>'mail','type'=>'MX','address'=>str_repeat('m',63).'.example.com.','mx_pref'=>10],['name'=>'verify','type'=>'TXT','address'=>str_repeat('synthetic-text ',12),'mx_pref'=>10]],
    'srv'=>[['service'=>'sip','protocol'=>'tcp','target'=>'sip.example.com.','priority'=>0,'weight'=>5,'port'=>443]],
    'ds'=>[['key_tag'=>27578,'algorithm'=>13,'digest_type'=>2,'digest'=>str_repeat('A',64)]],
    'glue'=>[['host'=>'ns1.'.str_repeat('a',63).'.com','ip'=>'2606:4700:4700::1111']]];
$section=static function(string $kind,?array $rows)use($records):array {
    $s=['label'=>['dns'=>'DNS records','srv'=>'SRV records','ds'=>'DNSSEC','glue'=>'Child nameservers'][$kind],'records'=>$rows,'actions'=>[]];
    $actions=match($kind){'ds'=>['ds_add','ds_delete'],'glue'=>['glue_add','glue_update','glue_delete'],default=>[$kind]};
    foreach($actions as$a){$s['actions'][$a]='/domains/1/manage/'.$a;}$s['url']=reset($s['actions']);
    if(in_array($kind,['dns','srv'],true)&&$rows!==null){$s['digest']=ManagementPayload::digest($rows,$kind);}return $s;
};
$draw=static function(array $sections,array $errors=[])use($enom):string {
    app('view')->share('errors',(new Illuminate\Support\ViewErrorBag)->put('default',new Illuminate\Support\MessageBag($errors)));
    $d=new OwnedDomain(...[...get_object_vars($enom),'management'=>$sections]);
    return view('domains::show',['domain'=>$d])->render();
};
foreach(['dns','srv','ds','glue']as$kind){
    $group=['dns'=>'dns-records','srv'=>'srv-records','ds'=>'ds-records','glue'=>'glue-hosts'][$kind];
    $pages[$group]=$draw([$kind=>$section($kind,$records[$kind])]);
    $pages[$group.'-held']=$draw([$kind=>$section($kind,null)]);
    $fields=match($kind){'dns'=>['dns.records.0.name','dns.records.0.address'],'srv'=>['srv.records.0.service','srv.records.0.port'],'ds'=>['ds.key_tag','ds.digest'],default=>['glue.host','glue.ip']};
    $errorSection=$section($kind,$records[$kind]);
    if(in_array($kind,['dns','srv'],true)){$draft=$records[$kind];if($kind==='dns'){$draft[0]['name']='bad host';$draft[0]['address']='bad-address';}else{$draft[0]['service']='_sip';$draft[0]['port']='bad-port';}$errorSection['retry']=['action'=>$kind,'values'=>['records'=>$draft,'digest'=>$errorSection['digest']]];}
    if($kind==='glue'){$errorSection['retry']=['action'=>'glue_update','values'=>['host'=>$records['glue'][0]['host'],'ip'=>'bad-address']];}
    if($kind==='ds'){$errorSection['retry']=['action'=>'ds_add','values'=>['ds'=>['key_tag'=>'65536','algorithm'=>'13','digest_type'=>'2','digest'=>'bad-digest']]];}
    $pages[$group.'-errors']=$draw([$kind=>$errorSection],(new \Paymenter\Extensions\Servers\Domains\Services\OwnerFormValidation($fields))->messages($enom->name));
}
$rows=array_map(fn($i)=>['name'=>'record'.$i,'type'=>'TXT','address'=>'synthetic text','mx_pref'=>10],range(1,50));
$pages['dns-records-max']=$draw(['dns'=>$section('dns',$rows)]);
$pages['ns-contact-errors']=$draw($ns->management,['nameservers'=>'Check the nameservers.','contact.first_name'=>'Check this contact detail.','contact.state_province'=>'Check this contact detail.','contact.phone'=>'Check this contact detail.']);
$pages['unsupported']=$draw($ns->management);
$pages['shared-regression']=$draw($enom->management);
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
// Reveal native details for the standalone review fixtures, preserving all actual form markup.
foreach(['ds-records','glue-hosts']as$name){$pages[$name]=str_replace('<details class="domains-more"','<details open class="domains-more"',$pages[$name]);}
